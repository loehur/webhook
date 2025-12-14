<?php

class LogHelper
{
    /**
     * Menulis log ke file
     *
     * @param string $text Teks yang akan ditulis ke log
     * @param string $service Nama service (moota, tokopay, midtrans, dll)
     */
    public static function write($text, $service = 'general')
    {
        $assets_dir = "logs/" . strtolower($service) . "/" . date('Y/') . date('m/');
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
