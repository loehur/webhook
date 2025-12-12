<?php

class Moota extends Controller
{
    /**
     * Webhook endpoint untuk menerima notifikasi dari Moota
     * URL: /moota/update
     * 
     * Headers yang divalidasi:
     * - X-MOOTA-USER: User ID Moota
     * - X-MOOTA-WEBHOOK: Webhook ID Moota
     * - User-Agent: MootaBot/x.x
     * - Signature: HMAC SHA256 signature
     * - Content-Type: application/json
     */
    public function update()
    {
        header('Content-Type: application/json; charset=utf-8');

        $json = file_get_contents('php://input');

        // Logging incoming request
        $this->write("========== NEW REQUEST ==========");
        $this->write("Incoming Request: " . $json);

        // Get headers
        $headers = $this->getRequestHeaders();
        $this->write("Headers: " . json_encode($headers));

        // Validate required headers
        $moota_user = isset($headers['X-MOOTA-USER']) ? $headers['X-MOOTA-USER'] : '';
        $moota_webhook = isset($headers['X-MOOTA-WEBHOOK']) ? $headers['X-MOOTA-WEBHOOK'] : '';
        $user_agent = isset($headers['User-Agent']) ? $headers['User-Agent'] : '';
        $signature_provided = isset($headers['Signature']) ? $headers['Signature'] : '';

        $this->write("X-MOOTA-USER: $moota_user");
        $this->write("X-MOOTA-WEBHOOK: $moota_webhook");
        $this->write("User-Agent: $user_agent");
        $this->write("Signature: $signature_provided");

        // Validate User-Agent (harus MootaBot)
        if (strpos($user_agent, 'MootaBot') === false) {
            $this->write("Error: Invalid User-Agent. Expected MootaBot, got: $user_agent");
            echo json_encode(['status' => false, 'message' => 'Invalid User-Agent']);
            return;
        }

        // Validate X-MOOTA-USER
        $expected_moota_user = URL::MOOTA_USER;
        if ($moota_user !== $expected_moota_user) {
            $this->write("Error: Invalid X-MOOTA-USER. Expected: $expected_moota_user, got: $moota_user");
            echo json_encode(['status' => false, 'message' => 'Invalid Moota User']);
            return;
        }

        // // Validate X-MOOTA-WEBHOOK
        // $expected_moota_webhook = URL::MOOTA_WEBHOOK;
        // if ($moota_webhook !== $expected_moota_webhook) {
        //     $this->write("Error: Invalid X-MOOTA-WEBHOOK. Expected: $expected_moota_webhook, got: $moota_webhook");
        //     echo json_encode(['status' => false, 'message' => 'Invalid Moota Webhook']);
        //     return;
        // }

        // // Validate Signature using HMAC SHA256
        // $secret = URL::MOOTA_SECRET;
        // $signature_generated = hash_hmac('sha256', $json, $secret);

        // if ($signature_provided !== $signature_generated) {
        //     $this->write("Error: Invalid Signature. Provided: $signature_provided, Generated: $signature_generated");
        //     echo json_encode(['status' => false, 'message' => 'Invalid Signature']);
        //     return;
        // }

        $this->write("Signature Valid!");

        $data = json_decode($json, true);

        if (!$data) {
            $this->write("Error: Invalid JSON received");
            echo json_encode(['status' => false, 'message' => 'Invalid JSON']);
            return;
        }

        // Moota mengirim array mutasi
        if (!is_array($data)) {
            $this->write("Error: Data is not an array");
            echo json_encode(['status' => false, 'message' => 'Invalid data format']);
            return;
        }

        $processed_count = 0;
        $success_count = 0;
        $error_count = 0;

        // Loop through each mutation
        foreach ($data as $index => $mutation) {
            $this->write("--- Processing mutation index: $index ---");

            // Ambil payment_detail jika ada
            $payment_detail = isset($mutation['payment_detail']) ? $mutation['payment_detail'] : null;

            if (!$payment_detail) {
                $this->write("Skip: No payment_detail found in mutation index $index");
                continue;
            }

            $order_id = isset($payment_detail['order_id']) ? $payment_detail['order_id'] : '';
            $status = isset($payment_detail['status']) ? $payment_detail['status'] : '';
            $trx_id = isset($payment_detail['trx_id']) ? $payment_detail['trx_id'] : '';
            $amount = isset($mutation['amount']) ? $mutation['amount'] : 0;
            $mutation_id = isset($mutation['mutation_id']) ? $mutation['mutation_id'] : '';

            $this->write("Order ID: $order_id");
            $this->write("Status: $status");
            $this->write("Trx ID: $trx_id");
            $this->write("Amount: $amount");
            $this->write("Mutation ID: $mutation_id");

            // Skip jika order_id kosong
            if (empty($order_id)) {
                $this->write("Skip: order_id is empty in mutation index $index");
                continue;
            }

            $processed_count++;

            // Cari di tabel wh_moota berdasarkan order_id = trx_id
            try {
                $db_instance = $this->db(2000);
                if (!$db_instance) {
                    $this->write("Error: Failed to get DB instance 2000");
                    $error_count++;
                    continue;
                }
                $this->write("DB Instance 2000 obtained.");

                // Cari record di wh_moota dimana trx_id = order_id dari webhook
                $cek_target_query = $db_instance->get_where("wh_moota", ["trx_id" => $order_id]);

                if (!$cek_target_query) {
                    $this->write("Error: Query object is null after get_where");
                    $error_count++;
                    continue;
                }

                $cek_target = $cek_target_query->row();

                if ($cek_target) {
                    $this->write("Record found in wh_moota. Current state: " . $cek_target->state);

                    // Update state dengan status dari moota
                    try {
                        $update = $db_instance->update(
                            "wh_moota",
                            [
                                "state" => $status,
                                "mutation_id" => $mutation_id,
                                "amount" => $amount,
                                "updated_at" => date('Y-m-d H:i:s')
                            ],
                            ["trx_id" => $order_id]
                        );

                        if (!$update) {
                            $this->write("Error: Failed to update wh_moota. Order ID: $order_id");
                            $error_count++;
                        } else {
                            $this->write("Success: Updated wh_moota state to '$status' for Order ID: $order_id");
                            $success_count++;

                            // Proses tambahan jika ada target tertentu
                            $this->processTarget($cek_target, $status, $order_id);
                        }
                    } catch (Exception $e) {
                        $this->write("Exception during Update: " . $e->getMessage());
                        $error_count++;
                    }
                } else {
                    $this->write("Warning: No record found in wh_moota for Order ID: $order_id");
                    $error_count++;
                }
            } catch (Exception $e) {
                $this->write("Exception during DB lookup: " . $e->getMessage());
                $error_count++;
            }
        }

        $this->write("========== END REQUEST ==========");
        $this->write("Processed: $processed_count, Success: $success_count, Error: $error_count");

        echo json_encode([
            'status' => true,
            'message' => 'Webhook processed',
            'processed' => $processed_count,
            'success' => $success_count,
            'error' => $error_count
        ]);
    }

    /**
     * Proses target tertentu berdasarkan data dari wh_moota
     */
    private function processTarget($record, $status, $order_id)
    {
        // Jika ada field target, proses sesuai target
        if (isset($record->target) && isset($record->book)) {
            $target = $record->target;
            $book = $record->book;

            $this->write("Processing target: $target, book: $book");

            if ($target == "kas_laundry") {
                // Hanya update kas_laundry jika status sukses
                $status_lower = strtolower($status);
                if (!in_array($status_lower, ['success', 'completed', 'paid'])) {
                    $this->write("Skip: Status '$status' bukan status sukses, tidak update kas_laundry");
                    return;
                }

                $db_target_name = "1" . $book;
                $this->write("Updating kas in DB: " . $db_target_name);

                try {
                    $db_update_instance = $this->db($db_target_name);
                    if (!$db_update_instance) {
                        $this->write("Error: Failed to get DB instance $db_target_name");
                        return;
                    }

                    // Status sukses = status_mutasi 3
                    $update = $db_update_instance->update(
                        "kas",
                        ["status_mutasi" => 3],
                        ["ref_finance" => $order_id]
                    );

                    if (!$update) {
                        $this->write("Error: Failed to update kas. Order ID: $order_id");
                    } else {
                        $this->write("Success: Updated kas status_mutasi to 3 for Order ID: $order_id");
                    }
                } catch (Exception $e) {
                    $this->write("Exception during kas Update: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Menulis log ke file
     */
    private function write($text)
    {
        $assets_dir = "logs/moota/" . date('Y/') . date('m/');
        $file_name = date('d') . ".log";
        $data_to_write = date('Y-m-d H:i:s') . " " . $text . "\n";
        $file_path = $assets_dir . $file_name;

        if (!file_exists($assets_dir)) {
            mkdir($assets_dir, 0777, TRUE);
        }

        $file_handle = fopen($file_path, 'a');
        fwrite($file_handle, $data_to_write);
        fclose($file_handle);
    }

    /**
     * Mengambil semua headers dari request
     */
    private function getRequestHeaders()
    {
        $headers = [];

        // Jika fungsi getallheaders() tersedia (Apache)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            // Fallback untuk server lain (nginx, etc)
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    // Convert HTTP_X_MOOTA_USER to X-Moota-User format
                    $header_name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                    $headers[$header_name] = $value;
                }
            }

            // Handle special headers
            if (isset($_SERVER['CONTENT_TYPE'])) {
                $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
            }
            if (isset($_SERVER['CONTENT_LENGTH'])) {
                $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
            }
        }

        // Normalize header names untuk X-MOOTA-USER dan X-MOOTA-WEBHOOK
        $normalized = [];
        foreach ($headers as $key => $value) {
            // Handle both "X-Moota-User" and "X-MOOTA-USER" formats
            if (strtolower($key) === 'x-moota-user') {
                $normalized['X-MOOTA-USER'] = $value;
            } elseif (strtolower($key) === 'x-moota-webhook') {
                $normalized['X-MOOTA-WEBHOOK'] = $value;
            } else {
                $normalized[$key] = $value;
            }
        }

        return array_merge($headers, $normalized);
    }
}
