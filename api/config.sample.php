<?php
// Copy to api/config.php for local development. Production config is written by install.php.
return [
  'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'gymapp', 'user' => 'root', 'pass' => ''],
  'app_url' => 'http://localhost:8080',
  'app_key' => 'change-me-to-64-random-hex-characters',
  'cron_key' => 'change-me',
  'demo_mode' => true,
  'cookie_secure' => false,
  'debug' => true,
  'timezone' => 'Asia/Kolkata',
  'client_ip_header' => null,
];
