<?php

class Tokopay extends Controller
{
    public function update()
    {
        header('Content-Type: application/json; charset=utf-8');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        // Logging
        LogHelper::write("Incoming Request: " . $json, 'tokopay');

        if (!$data) {
            echo json_encode(['status' => false, 'message' => 'Invalid JSON']);
            return;
        }

        $merchant_id = URL::TOKOPAY_MERCHANT;
        $secret = URL::TOKOPAY_SECRET;

        $reff_id = isset($data['reff_id']) ? $data['reff_id'] : '';
        $signature_provided = isset($data['signature']) ? $data['signature'] : '';

        if (empty($reff_id) || empty($signature_provided)) {
            echo json_encode(['status' => false, 'message' => 'Missing parameter']);
            LogHelper::write("Error: Missing parameter", 'tokopay');
            return;
        }

        // Validate Signature: md5(merchant_id:secret:reff_id)
        $signature_generated = md5($merchant_id . ':' . $secret . ':' . $reff_id);

        if ($signature_provided !== $signature_generated) {
            echo json_encode(['status' => false, 'message' => 'Invalid Signature']);
            LogHelper::write("Error: Invalid Signature. Provided: $signature_provided, Generated: $signature_generated", 'tokopay');
            return;
        }

        // Process Transaction
        $status = isset($data['status']) ? $data['status'] : '';

        if (isset($data['status'])) {
            $db_main = $this->db(2000);
            if (!$db_main) {
                LogHelper::write("Error: Failed to get DB instance 2000 for main update", 'tokopay');
                return;
            }

            $up_wh = $db_main->update("wh_tokopay", ["state" => $status], ["ref_id" => $reff_id]);
            if (!$up_wh) {
                LogHelper::write("Error: Query object is null after get_where in DB main update", 'tokopay');
                return;
            }
        }

        if ($status == 'Success' || $status == 'Completed') {
            LogHelper::write("Searching for reference: $reff_id in tokopay table...", 'tokopay');

            // Debugging DB connection and query
            try {
                $db_instance = $this->db(2000);
                if (!$db_instance) {
                    LogHelper::write("Error: Failed to get DB instance 2000", 'tokopay');
                    return;
                }
                LogHelper::write("DB Instance 2000 obtained.", 'tokopay');

                $cek_target_query = $db_instance->get_where("wh_tokopay", ["ref_id" => $reff_id]);
                if (!$cek_target_query) {
                    LogHelper::write("Error: Query object is null after get_where", 'tokopay');
                    return;
                }

                $cek_target = $cek_target_query->row();
            } catch (Exception $e) {
                LogHelper::write("Exception during DB lookup: " . $e->getMessage(), 'tokopay');
                return;
            }

            if ($cek_target) {
                LogHelper::write("Reference found. Book: " . $cek_target->book . ", Target: " . $cek_target->target, 'tokopay');

                $book = $cek_target->book;
                $target = $cek_target->target;

                if ($target == "kas_laundry") {
                    $i = 2021;
                    while ($i <= $book) {
                        $db_target_name = "1" . $i;
                        $i++;
                        LogHelper::write("Updating kas in DB: " . $db_target_name, 'tokopay');

                        try {
                            $db_update_instance = $this->db($db_target_name);
                            if (!$db_update_instance) {
                                LogHelper::write("Error: Failed to get DB instance $db_target_name", 'tokopay');
                                continue;
                            }

                            $update = $db_update_instance->update("kas", ["status_mutasi" => 3], ["ref_finance" => $reff_id]);

                            if (!$update) {
                                LogHelper::write("Error: Failed to update kas. Ref ID: $reff_id", 'tokopay');
                            } else {
                                LogHelper::write("Success: Updated kas for Ref ID: $reff_id", 'tokopay');
                            }
                        } catch (Exception $e) {
                            LogHelper::write("Exception during Update: " . $e->getMessage(), 'tokopay');
                        }
                    }
                } else {
                    LogHelper::write("Error: Target not 'kas_laundry'. Ref ID: $reff_id, Target: $target", 'tokopay');
                }
            } else {
                LogHelper::write("Error: Target not found in tokopay table. Ref ID: $reff_id", 'tokopay');
            }
        } else {
            LogHelper::write("Error: Invalid Status. Status: $status. Ref ID: $reff_id", 'tokopay');
        }

        LogHelper::write("End Request. Signature Valid. Status: $status. Ref ID: $reff_id", 'tokopay');
        echo json_encode(['status' => true, 'message' => 'Success']);
    }
}
