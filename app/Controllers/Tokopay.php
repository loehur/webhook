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
        
        if (empty($ref_id) || empty($signature_provided)) {
             echo json_encode(['status' => false, 'message' => 'Missing parameter']);
             $this->write("Error: Missing parameter");
             return;
        }

        // Validate Signature: md5(merchant_id:secret:ref_id)
        $signature_generated = md5($merchant_id . ':' . $secret . ':' . $ref_id);

        if ($signature_provided !== $signature_generated) {
            echo json_encode(['status' => false, 'message' => 'Invalid Signature']);
            $this->write("Error: Invalid Signature. Provided: $signature_provided, Generated: $signature_generated");
            return;
        }

        // Process Transaction
        $status = isset($data['status']) ? $data['status'] : '';
        if ($status == 'Success' || $status == 'Completed') {
            $cek_target = $this->db(2000)->get_where("tokopay", ["ref_id" => $reff_id])->row();
            if ($cek_target) {
                $book = $cek_target->book;
                $target = $cek_target->target;
                if($target == "kas_laundry"){
                    $update = $this->db("1".$book)->update("kas", ["status_mutasi" => 3], ["ref_finance" => $reff_id]);
                    if(!$update){
                        $this->write("Error: Failed to update kas. Ref ID: $reff_id");
                    }
                }
            }
        }
        
        $this->write("Signature Valid. Status: $status. Ref ID: $reff_id");
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
