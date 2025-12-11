<?php
date_default_timezone_set("Asia/Jakarta");
$GLOBALS['now'] = date("Y-m-d H:i:s");

spl_autoload_register(function ($class) {
     require_once 'Core/' . $class . '.php';
});
