<?php

class Midtrans extends Controller
{
    public function update()
    {
        header('Content-Type: application/json; charset=utf-8');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        // Logging
        LogHelper::write("Incoming Request: " . $json, 'midtrans');

        if (!$data) {
            echo json_encode(['status' => false, 'message' => 'Invalid JSON']);
            return;
        }

        $server_key = URL::MIDTRANS_SERVER_KEY;

        $order_id = isset($data['order_id']) ? $data['order_id'] : '';
        $status_code = isset($data['status_code']) ? $data['status_code'] : '';
        $gross_amount = isset($data['gross_amount']) ? $data['gross_amount'] : '';
        $signature_provided = isset($data['signature_key']) ? $data['signature_key'] : '';

        if (empty($order_id) || empty($signature_provided)) {
            echo json_encode(['status' => false, 'message' => 'Missing parameter']);
            LogHelper::write("Error: Missing parameter", 'midtrans');
            return;
        }

        // Validate Signature: hash("sha512", order_id + status_code + gross_amount + ServerKey)
        $signature_generated = hash("sha512", $order_id . $status_code . $gross_amount . $server_key);

        if ($signature_provided !== $signature_generated) {
            echo json_encode(['status' => false, 'message' => 'Invalid Signature']);
            LogHelper::write("Error: Invalid Signature. Provided: $signature_provided, Generated: $signature_generated", 'midtrans');
            return;
        }

        // Process Transaction
        $transaction_status = isset($data['transaction_status']) ? $data['transaction_status'] : '';
        $fraud_status = isset($data['fraud_status']) ? $data['fraud_status'] : '';

        // Map Midtrans status to our status
        $status = $this->mapMidtransStatus($transaction_status, $fraud_status);

        if (isset($data['transaction_status'])) {
            $db_main = $this->db(2000);
            if (!$db_main) {
                LogHelper::write("Error: Failed to get DB instance 2000 for main update", 'midtrans');
                return;
            }

            $up_wh = $db_main->update("wh_midtrans", ["state" => $status], ["ref_id" => $order_id]);
            if (!$up_wh) {
                LogHelper::write("Error: Query object is null after update in DB main", 'midtrans');
                return;
            }
        }

        if ($status == 'Success' || $status == 'Completed') {
            LogHelper::write("Searching for reference: $order_id in midtrans table...", 'midtrans');

            // Debugging DB connection and query
            try {
                $db_instance = $this->db(2000);
                if (!$db_instance) {
                    LogHelper::write("Error: Failed to get DB instance 2000", 'midtrans');
                    return;
                }
                LogHelper::write("DB Instance 2000 obtained.", 'midtrans');

                $cek_target_query = $db_instance->get_where("wh_midtrans", ["ref_id" => $order_id]);
                if (!$cek_target_query) {
                    LogHelper::write("Error: Query object is null after get_where", 'midtrans');
                    return;
                }

                $cek_target = $cek_target_query->row();
            } catch (Exception $e) {
                LogHelper::write("Exception during DB lookup: " . $e->getMessage(), 'midtrans');
                return;
            }

            if ($cek_target) {
                LogHelper::write("Reference found. Book: " . $cek_target->book . ", Target: " . $cek_target->target, 'midtrans');

                $book = $cek_target->book;
                $target = $cek_target->target;

                if ($target == "kas_laundry") {
                    $i = 2021;
                    while ($i <= $book) {
                        $db_target_name = "1" . $i;
                        $i++;
                        LogHelper::write("Updating kas in DB: " . $db_target_name, 'midtrans');

                        try {
                            $db_update_instance = $this->db($db_target_name);
                            if (!$db_update_instance) {
                                LogHelper::write("Error: Failed to get DB instance $db_target_name", 'midtrans');
                                continue;
                            }

                            $update = $db_update_instance->update("kas", ["status_mutasi" => 3], ["ref_finance" => $order_id]);

                            if (!$update) {
                                LogHelper::write("Error: Failed to update kas. Ref ID: $order_id", 'midtrans');
                            } else {
                                LogHelper::write("Success: Updated kas for Ref ID: $order_id", 'midtrans');
                            }
                        } catch (Exception $e) {
                            LogHelper::write("Exception during Update: " . $e->getMessage(), 'midtrans');
                        }
                    }
                } else {
                    LogHelper::write("Error: Target not 'kas_laundry'. Ref ID: $order_id, Target: $target", 'midtrans');
                }
            } else {
                LogHelper::write("Error: Target not found in midtrans table. Ref ID: $order_id", 'midtrans');
            }
        } else {
            LogHelper::write("Error: Invalid Status. Status: $status. Ref ID: $order_id", 'midtrans');
        }

        LogHelper::write("End Request. Signature Valid. Status: $status. Ref ID: $order_id", 'midtrans');
        echo json_encode(['status_code' => '200', 'status_message' => 'Success']);
    }

    private function mapMidtransStatus($transaction_status, $fraud_status)
    {
        // Map Midtrans transaction status to our internal status
        if ($transaction_status == 'capture') {
            if ($fraud_status == 'accept') {
                return 'Success';
            } else if ($fraud_status == 'challenge') {
                return 'Pending';
            } else {
                return 'Failed';
            }
        } else if ($transaction_status == 'settlement') {
            return 'Completed';
        } else if ($transaction_status == 'pending') {
            return 'Pending';
        } else if ($transaction_status == 'deny' || $transaction_status == 'expire' || $transaction_status == 'cancel') {
            return 'Failed';
        } else {
            return 'Unknown';
        }
    }
}
