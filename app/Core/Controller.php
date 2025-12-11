<?php

require 'app/Config/URL.php';

class Controller extends URL
{
    public function db($db = 0)
    {
        require_once "app/Core/DB.php";
        return DB::getInstance($db);
    }
}
