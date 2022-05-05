<?php

namespace App\Http\Controllers\User;

use App\DataTables\User\DepositDataTable;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\DepositMethod;
use chillerlan\QRCode\QRCode;
use Exception;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class DepositController extends Controller {
    public function get(DepositDataTable $dataTable) {
        $components['breadcrumb'] = (object) [
			'first'  => 'Deposit',
			'second' => website_config('main')->website_name
        ];
        $components['deposit_methods'] = DepositMethod::where([['type', '<>', 'Direct'], ['status', '1']])->oldest('name')->get();
    	$components['methods'] = Deposit::where('user_id', Auth::user()->id)->get(['deposit_method_id', 'deposit_method_name'])->unique('deposit_method_id')->unique('deposit_method_name');
        $components['statuses'] = ['Pending', 'Success', 'Canceled'];
        $components['created_at'] = Deposit::where('user_id', Auth::user()->id)->selectRaw('DATE(created_at) AS created_at')->distinct()->latest('created_at')->get();
        return $dataTable->render('user.deposit.index', $components);
        // return view('user.deposit.index', $components);
    }
    public function post(PostRequest $request) {
        if ($request->ajax() == false) abort('404');
        if (Auth::user()->username == 'demouser') {
            return response()->json([
                'status'  => false, 
                'type'    => 'alert',
                'message' => 'Aksi tidak diperbolehkan.'
            ]);
        }
        $input_data = [
            'user_id'           => Auth::user()->id,
            'deposit_method_id' => escape_input($request->deposit_method),
            'amount'            => fixed_amount($request->amount),
            'phone_number'      => $request->phone_number,
            'balance'           => 0,
            'status'            => 'Pending',
            'ip_address'        => $request->ip()
        ];
        $check_data = [
            'deposit_method' => DepositMethod::find($input_data['deposit_method_id']),
            'deposit_limit'  => Deposit::where([['user_id', Auth::user()->id], ['status', 'Pending']])->whereDate('created_at', date('Y-m-d'))->get(),
        ];
        if ($input_data['amount'] < $check_data['deposit_method']['min']) {
            return response()->json([
                'status'  => false, 
                'type'    => 'alert',
                'message' => 'Minimal deposit adalah Rp '.number_format($check_data['deposit_method']['min'],0,',','.').'.'
            ]);
        } elseif ($check_data['deposit_limit']->count() >= 1) {
            return response()->json([
                'status'  => false, 
                'type'    => 'alert',
                'message' => 'Anda masih memiliki '.$check_data['deposit_limit']->count().' Deposit berstatus Pending.'
            ]);
        } else {
            $snapToken = '';
            if (!in_array($check_data['deposit_method']['payment'], ['Pulsa', 'Other','bank', 'e-wallet']) AND $check_data['deposit_method']['type'] == 'Manual') {
                for ($i = 0; $i < 100; $i++) {
                    $input_data['amount'] = $input_data['amount'] + rand(150, 300);
                    if (Deposit::where('amount', $input_data['amount'])->first() == true) continue;
                    break;
                }
            }
            
            
            // $snapToken = '';
            //if (!in_array($check_data['deposit_method']['payment'], ['Pulsa', 'Other']) AND $check_data['deposit_method']['type'] == 'Auto') {
            //  for ($i = 0; $i < 100; $i++) {
            //    $input_data['amount'] = $input_data['amount'] + rand(100, 1000);
            //  if (Deposit::where('amount', $input_data['amount'])->first() == true) continue;
            //    break;
            //    }
            //    }
            
            if ($check_data['deposit_method']['is_tripay'] == '1') {
                for ($i = 0; $i < 100; $i++) {
                    $merchantRef = rand(1, 1000000);
                    if (Deposit::where('reference', $merchantRef)->first() == true) continue;
                    break;
                }
                $header_api = ["Authorization: Bearer ".website_config('tripay_payment')->api_key];
                $post_api = [
                    'method'            => $check_data['deposit_method']['tripay_payment_code'],
                    'merchant_ref'      => $merchantRef,
                    'amount'            => $input_data['amount'],
                    'customer_name'     => preg_replace('/\s/', '', website_config('main')->website_name).' - '.Auth::user()->username,
                    'customer_email'    => Auth::user()->email,
                    'customer_phone'    => Auth::user()->phone_number,
                    'allow_html'        => 0,
                    'order_items'       => [
                        [
                        'sku'       => 'Deposit',
                        'name'      => $check_data['deposit_method']['name'],
                        'price'     => $input_data['amount'],
                        'quantity'  => 1
                        ]
                    ],
                    'callback_url'      => url('callback'),
                    'return_url'        => url('redirect'),
                    'expired_time'      => (time()+(24*60*60)), // 24 jam
                    'signature'         => hash_hmac('sha256', website_config('tripay_payment')->merchant_code.$merchantRef.$input_data['amount'], website_config('tripay_payment')->private_key)
                ];
                $curl = tripay_curl('https://tripay.co.id/api/transaction/create', $header_api, $post_api, 'post');
                $curl_response = json_decode($curl, true);
                if (isset($curl_response['success']) AND $curl_response['success'] == false) {
                    return response()->json([
                        'status'  => false, 
                        'type'    => 'alert',
                        'message' => 'Silahkan coba lagi nanti.'
                    ]);
                }
                $input_data['reference'] = $curl_response['data']['reference'];
                $iteration = 1;
                foreach ($curl_response['data']['instructions'] as $item) {
                    $instructions = '<h5>'.$iteration.'. '.$item['title'].'</h5>';
                    $instructions .= '<ol>';
                    foreach ($item['steps'] as $step) {
                        $instructions .= '<li>'.$step.'</li>';
                    }
                    $instructions .= '</ol>';
                    $instructions_array[] = $instructions;
                    $iteration++;
                }
                $input_data['additional_note'] = implode('', $instructions_array);
                $check_data['deposit_method']['note'] = implode('', $instructions_array);
                if (in_array($check_data['deposit_method']['tripay_payment_code'], ['QRIS', 'QRISC']) == true) {
                    $input_data['additional_note'] .=  '<img src="'.(new QRCode())->render($curl_response['data']['qr_string']).'" alt="QR Code" />';
                    $check_data['deposit_method']['note'] .= '<img src="'.(new QRCode())->render($curl_response['data']['qr_string']).'" alt="QR Code" />';
                }
            }
            if ($check_data['deposit_method']['is_midtrans'] == '1') {
                for ($i = 0; $i < 100; $i++) {
                    $merchantRef = rand(1, 1000000);
                    if (Deposit::where('reference', $merchantRef)->first() == true) continue;
                    break;
                }
                $header_api = [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Basic '.base64_encode(website_config('midtrans_payment')->server_key.':').'',
                ];
                $post_api = [
                    'transaction_details' => [
                        'order_id' => $merchantRef,
                        'gross_amount' => $input_data['amount'],
                    ],
                    'callbacks' => [
                        'finish' => ''
                    ]
                ];

                $curl = midtrans_curl('https://app.midtrans.com/snap/v1/transactions', $header_api, $post_api, 'post');
                $curl_response = json_decode($curl, true);
                if (!isset($curl_response['token'])) {
                    return response()->json([
                        'status'  => false, 
                        'type'    => 'alert',
                        'message' => 'Silahkan coba lagi nanti.'
                    ]);
                }
                $input_data['reference'] = $merchantRef;
                $snapToken = $curl_response['token'];
                $input_data['token'] = $snapToken;
                $input_data['additional_note'] = "<button onclick=\"snap_pay('$snapToken')\" class=\"btn btn-sm btn-success\">Bayar Disini</button>";
                $check_data['deposit_method']['note'] = $input_data['additional_note'];
            }
            $input_data['balance'] = $input_data['amount'] * $check_data['deposit_method']['rate'];
            $input_data['deposit_method_name'] = $check_data['deposit_method']['name'];
            $input_data['additional_note'] = $check_data['deposit_method']['note'];
            $insert_data = Deposit::create($input_data);
            if ($insert_data == true) {
                if (json_decode(Auth::user()->notification)->deposit == '1') {
                    $details = [
                        'name'       => Auth::user()->full_name,
                        'id'         => $insert_data->id,
                        'method'     => $check_data['deposit_method']['name'].' ('.$check_data['deposit_method']['payment'].' - '.$check_data['deposit_method']['type'].')',
                        'amount'     => $input_data['amount'],
                        'status'     => 'Pending',
                        'ip_address' => $request->ip(),
                    ];
                    $this->send_email_user($details, Auth::user()->email);
                }
                if (website_config('notification')->email <> '' AND website_config('notification')->deposit == '1') {
                    $details = [
                        'username'   => Auth::user()->username,
                        'full_name'  => Auth::user()->full_name,
                        'id'         => $insert_data->id,
                        'method'     => $check_data['deposit_method']['name'].' ('.$check_data['deposit_method']['payment'].' - '.$check_data['deposit_method']['type'].')',
                        'amount'     => $input_data['amount'],
                        'status'     => 'Pending',
                        'ip_address' => $request->ip(),
                    ];
                    $this->send_email_admin($details, website_config('notification')->email);
                }
                // session()->flash('result', [
                //     'alert'   => 'success', 
                //     'title'   => 'Berhasil', 
                //     'message' => '
                //         <br /><b>ID:</b> '.$insert_data->id.'
                //         <br /><b>Metode:</b> '.$check_data['deposit_method']['name'].'
                //         <br /><b>Jumlah:</b> Rp '.number_format($input_data['amount'],0,',','.').'
                //         <br /><b>Catatan:</b> '.$check_data['deposit_method']['note'].'
                //         <br /><b>Harap men-transfer sesuai jumlah deposit.</b>
                //     '
                // ]);
                return response()->json([
                    'status'  => true, 
                    'is_midtrans' => $check_data['deposit_method']['is_midtrans'],
                    'snap_token' => $snapToken,
                    'deposit_detail' => url('deposit/detail/'.$insert_data->id.''),
                    'message' => '
                        Deposit anda segera diproses, lunasi pembayaran anda.
                        <br /><b>ID:</b> '.$insert_data->id.'
                        <br /><b>Metode:</b> '.$check_data['deposit_method']['name'].'
                        <br /><b>Jumlah:</b> Rp '.number_format($input_data['amount'],0,',','.').'
                        <br /><b>Catatan:</b> '.$check_data['deposit_method']['note'].'
                        <br /><b>Harap men-transfer sesuai jumlah deposit.</b>
                    '
                ]);
            } else {
                return response()->json([
                    'status'  => false, 
                    'type'    => 'alert',
                    'message' => 'Terjadi kesalahan.'
                ]);
            }
        }
    }
    public function detail(Deposit $target, Request $request) {
		if ($request->ajax() == false) abort('404');
        if ($request->method() <> 'GET') abort('404');
        if ($target->user_id <> Auth::user()->id) abort('404');
        return view('user.deposit.detail', compact('target'));
    }
    public function send_email_user($details = [], $to = '') {
		config(['mail.mailers.smtp.username' => website_config('smtp')->username]);
		config(['mail.mailers.smtp.password' => website_config('smtp')->password]);
		config(['mail.mailers.smtp.encryption' => website_config('smtp')->encryption]);
		config(['mail.mailers.smtp.port' => website_config('smtp')->port]);
		config(['mail.mailers.smtp.host' => website_config('smtp')->host]);
		config(['mail.from.address' => website_config('smtp')->from]);
		config(['mail.from.name' => website_config('main')->website_name]);
		try {
            Mail::send('user.mail.notification.deposit', $details, function($message) use ($details, $to) {
                $message
                 ->to($to, $details['name'])
                 ->from(config('mail.from.address'), config('mail.from.name'))
                 ->subject('Informasi Deposit - '.website_config('main')->website_name.'');
             });
			return true;
		} catch (Exception $message) {
			return true;
		}
    }
    public function send_email_admin($details = [], $to = '') {
		config(['mail.mailers.smtp.username' => website_config('smtp')->username]);
		config(['mail.mailers.smtp.password' => website_config('smtp')->password]);
		config(['mail.mailers.smtp.encryption' => website_config('smtp')->encryption]);
		config(['mail.mailers.smtp.port' => website_config('smtp')->port]);
		config(['mail.mailers.smtp.host' => website_config('smtp')->host]);
		config(['mail.from.address' => website_config('smtp')->from]);
		config(['mail.from.name' => website_config('main')->website_name]);
		try {
            Mail::send('admin.mail.notification.deposit', $details, function($message) use ($details, $to) {
                $message
                 ->to($to, 'Admin')
                 ->from(config('mail.from.address'), config('mail.from.name'))
                 ->subject('Informasi Deposit - '.website_config('main')->website_name.'');
             });
			return true;
		} catch (Exception $message) {
			return true;
		}
    }
}

class PostRequest extends FormRequest {
    protected function prepareForValidation() {
        $this->merge([
            'amount' => $this->amount <> '' ? fixed_amount($this->amount) : '',
        ]);
    }
    protected function getValidatorInstance() {
        $instance = parent::getValidatorInstance();
        if ($instance->fails() == true) {
            throw new HttpResponseException(response()->json([
                'status'  => false, 
                'type'    => 'validation',
                'message' => parent::getValidatorInstance()->errors()
            ]));
        }
        return parent::getValidatorInstance();
    }
    public function rules(Request $request) {
        return [
            'deposit_method' => 'required|numeric|exists:jhonroot_deposit_methods,id',
            'amount'         => 'required|numeric|integer|min:0',
            'phone_number'   => $request->payment == 'pulsa' ? 'required|numeric|phone:ID,mobile' : '',
            // 'approval'          => 'required|in:1',
        ];
    }
    public function attributes() {
        return [
            'deposit_method' => 'Metode',
            'amount'         => 'Jumlah',
            'phone_number'   => 'No. Pengirim',
            'approval'       => 'Persetujuan',
        ];
    }
}
