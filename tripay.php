
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
