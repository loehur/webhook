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
        // Enable error logging
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
        ini_set('error_log', 'logs/moota/' . date('Y/m/') . date('d') . '_php_errors.log');

        header('Content-Type: application/json; charset=utf-8');

        $json = file_get_contents('php://input');

        // Logging incoming request
        LogHelper::write("========== NEW REQUEST ==========", 'moota');
        LogHelper::write("Incoming Request: " . $json, 'moota');

        // Get headers
        $headers = $this->getRequestHeaders();
        LogHelper::write("Headers: " . json_encode($headers), 'moota');

        // Validate required headers
        $moota_user = isset($headers['X-MOOTA-USER']) ? $headers['X-MOOTA-USER'] : '';
        $moota_webhook = isset($headers['X-MOOTA-WEBHOOK']) ? $headers['X-MOOTA-WEBHOOK'] : '';
        $user_agent = isset($headers['User-Agent']) ? $headers['User-Agent'] : '';
        $signature_provided = isset($headers['Signature']) ? $headers['Signature'] : '';

        LogHelper::write("X-MOOTA-USER: $moota_user", 'moota');
        LogHelper::write("X-MOOTA-WEBHOOK: $moota_webhook", 'moota');
        LogHelper::write("User-Agent: $user_agent", 'moota');
        LogHelper::write("Signature: $signature_provided", 'moota');

        // Validate User-Agent (harus MootaBot)
        if (strpos($user_agent, 'MootaBot') === false) {
            LogHelper::write("Error: Invalid User-Agent. Expected MootaBot, got: $user_agent", 'moota');
            echo json_encode(['status' => false, 'message' => 'Invalid User-Agent']);
            return;
        }

        // Validate X-MOOTA-USER
        $expected_moota_user = URL::MOOTA_USER;
        if ($moota_user !== $expected_moota_user) {
            LogHelper::write("Error: Invalid X-MOOTA-USER. Expected: $expected_moota_user, got: $moota_user", 'moota');
            echo json_encode(['status' => false, 'message' => 'Invalid Moota User']);
            return;
        }

        // Validate Signature using HMAC SHA256
        $secret = URL::MOOTA_SECRET;
        $signature_generated = hash_hmac('sha256', $json, $secret);

        if ($signature_provided !== $signature_generated) {
            LogHelper::write("Error: Invalid Signature. Provided: $signature_provided, Generated: $signature_generated", 'moota');
            echo json_encode(['status' => false, 'message' => 'Invalid Signature']);
            return;
        }

        LogHelper::write("Signature Valid!", 'moota');

        $data = json_decode($json, true);

        if (!$data) {
            LogHelper::write("Error: Invalid JSON received", 'moota');
            echo json_encode(['status' => false, 'message' => 'Invalid JSON']);
            return;
        }

        // Moota mengirim array mutasi
        if (!is_array($data)) {
            LogHelper::write("Error: Data is not an array", 'moota');
            echo json_encode(['status' => false, 'message' => 'Invalid data format']);
            return;
        }

        $processed_count = 0;
        $success_count = 0;
        $error_count = 0;

        // Loop through each mutation
        foreach ($data as $index => $mutation) {
            $processed_count++;

            LogHelper::write("--- Processing mutation index: $index ---", 'moota');

            if (isset($mutation['amount']) && isset($mutation['type']) && isset($mutation['bank_id']) && isset($mutation['mutation_id'])) {
                LogHelper::write("Mutation Amount: " . $mutation['amount'], 'moota');
                LogHelper::write("Mutation Type: " . $mutation['type'], 'moota');
                LogHelper::write("Mutation Bank ID: " . $mutation['bank_id'], 'moota');
            } else {
                LogHelper::write("Error: Missing required mutation fields in index $index", 'moota');
                $error_count++;
                continue;
            }

            $amount = $mutation['amount'];
            $type = $mutation['type'];
            $bank_id = $mutation['bank_id'];
            $mutation_id = $mutation['mutation_id'];

            if ($type !== 'CR') {
                LogHelper::write("Skip: Mutation type is not 'CR' in index $index", 'moota');
                continue;
            }

            //cek sudah ada mutation_id di wh_moota
            try {
                $db_instance = $this->db(2000);
                if (!$db_instance) {
                    LogHelper::write("Error: Failed to get DB instance 2000", 'moota');
                    $error_count++;
                    continue;
                }
                LogHelper::write("DB Instance 2000 obtained.", 'moota');
                LogHelper::write("Querying wh_moota for existing mutation_id: $mutation_id", 'moota');

                $cek_existing_query = $db_instance->get_where("wh_moota", [
                    "mutation_id" => $mutation_id
                ]);

                LogHelper::write("Query executed successfully", 'moota');

                if (!$cek_existing_query) {
                    LogHelper::write("Error: Query object is null after get_where for existing mutation_id check", 'moota');
                    $error_count++;
                    continue;
                }

                $existing_count = $cek_existing_query->num_rows();

                if ($existing_count > 0) {
                    LogHelper::write("Skip: Mutation ID $mutation_id already processed, found $existing_count records", 'moota');
                    continue;
                }
                LogHelper::write("No existing record found for mutation_id: $mutation_id", 'moota');
            } catch (Exception $e) {
                LogHelper::write("Exception during existing mutation_id check: " . $e->getMessage(), 'moota');
                LogHelper::write("Exception trace: " . $e->getTraceAsString(), 'moota');
                $error_count++;
                continue;
            } catch (Error $e) {
                LogHelper::write("Error during existing mutation_id check: " . $e->getMessage(), 'moota');
                LogHelper::write("Error trace: " . $e->getTraceAsString(), 'moota');
                $error_count++;
                continue;
            }

            //PASTIKAN BANK ID, NOMINAL, DAN STATE PENDING ADA DAN HANYA ADA SATU DI WH MOOTA
            try {
                $db_instance = $this->db(2000);
                if (!$db_instance) {
                    LogHelper::write("Error: Failed to get DB instance 2000", 'moota');
                    $error_count++;
                    continue;
                }
                LogHelper::write("DB Instance 2000 obtained.", 'moota');

                //Cek data state != PAID di wh_moota
                LogHelper::write("Querying wh_moota for bank_id: $bank_id, amount: $amount, state: PENDING", 'moota');

                $cek_pending_query = $db_instance->get_where("wh_moota", [
                    "bank_id" => $bank_id,
                    "amount" => $amount,
                ]);

                LogHelper::write("Query executed successfully", 'moota');

                if (!$cek_pending_query) {
                    LogHelper::write("Error: Query object is null after get_where for pending check", 'moota');
                    $error_count++;
                    continue;
                }

                $pending_count = $cek_pending_query->num_rows();

                if ($pending_count == 1) {
                    LogHelper::write("Pending record found for bank_id: $bank_id, amount: $amount", 'moota');
                    //udpate state jadi paid
                    $update = $db_instance->update(
                        "wh_moota",
                        [
                            "state" => 'paid',
                            "mutation_id" => $mutation_id,
                        ],
                        [
                            "bank_id" => $bank_id,
                            "amount" => $amount,
                            "state !=" => 'paid',
                        ],
                    );

                    if ($update) {
                        LogHelper::write("Updated state to paid for bank_id: $bank_id, amount: $amount", 'moota');
                        //UPDATE KAS JADI STATUS_MUTASI 3 DENGAN REF_FINANCE DARI wh_moota
                        $pending_record = $cek_pending_query->row();
                        $this->processTarget($pending_record, $pending_record->trx_id);
                    } else {
                        LogHelper::write("Failed to update state to paid for bank_id: $bank_id, amount: $amount", 'moota');
                        $error_count++;
                        continue;
                    }
                } elseif ($pending_count > 1) {
                    LogHelper::write("Skip: Expected 1 pending record, found $pending_count for bank_id: $bank_id, amount: $amount", 'moota');

                    $update_conflict = $db_instance->update_limit(
                        "wh_moota",
                        [
                            "state" => 'paid_waiting',
                            "updated_at" => date('Y-m-d H:i:s'),
                            "mutation_id" => $mutation_id,
                        ],
                        [
                            "bank_id" => $bank_id,
                            "amount" => $amount,
                            "state !=" => 'paid',
                        ],
                        1
                    );

                    if ($update_conflict) {
                        LogHelper::write("Conflict flag set for bank_id: $bank_id, amount: $amount", 'moota');
                    } else {
                        LogHelper::write("Failed to set conflict flag for bank_id: $bank_id, amount: $amount", 'moota');
                        $error_count++;
                    }

                    // cek sisa yang belum paid_waiting
                    $remaining_query = $db_instance->get_where("wh_moota", [
                        "bank_id" => $bank_id,
                        "amount" => $amount,
                        "state !=" => 'paid_waiting',
                    ]);
                    $remaining_count = $remaining_query->num_rows();
                    if ($remaining_count == 0) {
                        LogHelper::write("All conflicting records resolved for bank_id: $bank_id, amount: $amount", 'moota');
                        // update semua kas dengan trx id yang paid_waiting jadi status_mutasi 3
                        $conflict_records = $db_instance->get_where("wh_moota", [
                            "bank_id" => $bank_id,
                            "amount" => $amount,
                            "state" => 'paid_waiting',
                        ])->result();
                        foreach ($conflict_records as $conflict_record) {
                            $update_kas = $this->processTarget($conflict_record, $conflict_record->trx_id);
                            if ($update_kas !== false) {
                                // update state jadi paid
                                $update_final = $db_instance->update(
                                    "wh_moota",
                                    [
                                        "state" => 'paid',
                                    ],
                                    [
                                        "id" => $conflict_record->id,
                                    ]
                                );
                                if ($update_final) {
                                    LogHelper::write("Finalized conflicting record ID: " . $conflict_record->id . " to paid", 'moota');
                                } else {
                                    LogHelper::write("Failed to finalize conflicting record ID: " . $conflict_record->id, 'moota');
                                }
                            } else {
                                LogHelper::write("Failed to process target for conflicting record ID: " . $conflict_record->id, 'moota');
                            }
                        }
                    } else {
                        LogHelper::write("Remaining conflicting records: $remaining_count for bank_id: $bank_id, amount: $amount", 'moota');
                    }


                    continue;
                } else {
                    LogHelper::write("No pending record found for bank_id: $bank_id, amount: $amount", 'moota');
                    $error_count++;
                }
                LogHelper::write("Pending record check passed for bank_id: $bank_id, amount: $amount", 'moota');
            } catch (Exception $e) {
                LogHelper::write("Exception during pending record check: " . $e->getMessage(), 'moota');
                LogHelper::write("Exception trace: " . $e->getTraceAsString(), 'moota');
                $error_count++;
                continue;
            } catch (Error $e) {
                LogHelper::write("Error during pending record check: " . $e->getMessage(), 'moota');
                LogHelper::write("Error trace: " . $e->getTraceAsString(), 'moota');
                $error_count++;
                continue;
            }

            $success_count++;
        }

        LogHelper::write("========== END REQUEST ==========", 'moota');
        LogHelper::write("Processed: $processed_count, Success: $success_count, Error: $error_count", 'moota');

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
    private function processTarget($record, $order_id)
    {
        // Jika ada field target, proses sesuai target
        if (isset($record->target) && isset($record->book)) {
            $target = $record->target;
            $book = $record->book;

            LogHelper::write("Processing target: $target, book: $book", 'moota');

            if ($target == "kas_laundry") {
                $i = 2021;
                while ($i <= $book) {
                    $db_target_name = "1" . $i;
                    $i++;
                    LogHelper::write("Updating kas in DB: " . $db_target_name, 'moota');

                    try {
                        $db_update_instance = $this->db($db_target_name);
                        if (!$db_update_instance) {
                            LogHelper::write("Error: Failed to get DB instance $db_target_name", 'moota');
                            continue;
                        }

                        // Status sukses = status_mutasi 3
                        $update = $db_update_instance->update(
                            "kas",
                            ["status_mutasi" => 3],
                            ["ref_finance" => $order_id]
                        );

                        if (!$update) {
                            return false;
                            LogHelper::write("Error: Failed to update kas. Order ID: $order_id", 'moota');
                        } else {
                            LogHelper::write("Success: Updated kas status_mutasi to 3 for Order ID: $order_id", 'moota');
                        }
                    } catch (Exception $e) {
                        return false;
                        LogHelper::write("Exception during kas Update: " . $e->getMessage(), 'moota');
                    }
                }
            } else {
                LogHelper::write("No processing logic for target: $target", 'moota');
            }
        } else {
            LogHelper::write("No target or book field in record, skipping processTarget", 'moota');
            return false;
        }
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
