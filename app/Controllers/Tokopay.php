<?php

class Tokopay extends Controller
{
    public function update()
    {
        header('Content-Type: application/json; charset=utf-8');
        
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        // Logging
        $this->write("Incoming Request: " . $json);

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
             $this->write("Error: Missing parameter");
             return;
        }

        // Validate Signature: md5(merchant_id:secret:reff_id)
        $signature_generated = md5($merchant_id . ':' . $secret . ':' . $reff_id);

        if ($signature_provided !== $signature_generated) {
            echo json_encode(['status' => false, 'message' => 'Invalid Signature']);
            $this->write("Error: Invalid Signature. Provided: $signature_provided, Generated: $signature_generated");
            return;
        }

        // Process Transaction
        $status = isset($data['status']) ? $data['status'] : '';
        if ($status == 'Success' || $status == 'Completed') {
            $this->write("Searching for reference: $reff_id in tokopay table...");
            
            // Debugging DB connection and query
            try {
                $db_instance = $this->db(2000);
                if (!$db_instance) {
                    $this->write("Error: Failed to get DB instance 2000");
                    return;
                }
                $this->write("DB Instance 2000 obtained.");

                $cek_target_query = $db_instance->get_where("tokopay", ["ref_id" => $reff_id]);
                if (!$cek_target_query) {
                     $this->write("Error: Query object is null after get_where");
                     return;
                }

                $cek_target = $cek_target_query->row();
                
            } catch (Exception $e) {
                $this->write("Exception during DB lookup: " . $e->getMessage());
                return;
            }

            if ($cek_target) {
                $this->write("Reference found. Book: " . $cek_target->book . ", Target: " . $cek_target->target);
                
                $book = $cek_target->book;
                $target = $cek_target->target;
                
                if($target == "kas_laundry"){
                    $db_target_name = "1".$book;
                    $this->write("Updating kas in DB: " . $db_target_name);

                    try {
                        $db_update_instance = $this->db($db_target_name);
                         if (!$db_update_instance) {
                            $this->write("Error: Failed to get DB instance $db_target_name");
                            return;
                        }

                        $update = $db_update_instance->update("kas", ["status_mutasi" => 3], ["ref_finance" => $reff_id]);
                    
                        if(!$update){
                            $this->write("Error: Failed to update kas. Ref ID: $reff_id");
                        } else {
                            $this->write("Success: Updated kas for Ref ID: $reff_id");
                        }
                    } catch (Exception $e) {
                         $this->write("Exception during Update: " . $e->getMessage());
                    }

                } else {
                    $this->write("Error: Target not 'kas_laundry'. Ref ID: $reff_id, Target: $target");
                }
            } else {
                $this->write("Error: Target not found in tokopay table. Ref ID: $reff_id");
            }
        } else {
            $this->write("Error: Invalid Status. Status: $status. Ref ID: $reff_id");
        }
        
        $this->write("End Request. Signature Valid. Status: $status. Ref ID: $reff_id");
        echo json_encode(['status' => true, 'message' => 'Success']);
    }

    function write($text)
    {
        $assets_dir = "logs/tokopay/" . date('Y/') . date('m/');
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
}
