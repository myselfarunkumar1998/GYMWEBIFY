<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) return;
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

use App\Core\Config;

Config::load();
date_default_timezone_set(Config::get('timezone', 'Asia/Kolkata'));
ini_set('display_errors', Config::get('debug') ? '1' : '0');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
