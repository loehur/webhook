<?php
date_default_timezone_set("Asia/Jakarta");
$GLOBALS['now'] = date("Y-m-d H:i:s");

spl_autoload_register(function ($class) {
    // Try to load from Core directory
    if (file_exists(__DIR__ . '/Core/' . $class . '.php')) {
        require_once __DIR__ . '/Core/' . $class . '.php';
    }
    // Try to load from Helpers directory
    elseif (file_exists(__DIR__ . '/Helpers/' . $class . '.php')) {
        require_once __DIR__ . '/Helpers/' . $class . '.php';
    }
});
