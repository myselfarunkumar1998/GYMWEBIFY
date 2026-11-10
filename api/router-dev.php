<?php
// Local dev only: emulates the Bluehost .htaccess rules for `php -S`.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) { require __DIR__ . '/index.php'; return true; }
if (str_starts_with($path, '/iclock/')) { require __DIR__ . '/adms.php'; return true; }
$dist = getenv('GYM_DIST') ?: __DIR__ . '/../web/dist';
$f = realpath($dist . $path);
if ($f && str_starts_with($f, realpath($dist)) && is_file($f)) {
  $types = ['js'=>'text/javascript','css'=>'text/css','svg'=>'image/svg+xml','png'=>'image/png','json'=>'application/json','webmanifest'=>'application/manifest+json','html'=>'text/html','woff2'=>'font/woff2'];
  $ext = pathinfo($f, PATHINFO_EXTENSION);
  header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
  readfile($f); return true;
}
if (is_file($dist . '/index.html')) { header('Content-Type: text/html'); readfile($dist . '/index.html'); return true; }
http_response_code(404); echo 'Not found';
