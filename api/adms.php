<?php
declare(strict_types=1);

// eSSL / ZKTeco push endpoint. The site root .htaccess rewrites /iclock/* here.
require __DIR__ . '/src/bootstrap.php';

use App\App;
use App\Core\{Config, Response};
use App\Services\Adms;

try {
    if (!Config::isInstalled()) { Response::text('ERROR', 503)->send(); exit; }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $body = file_get_contents('php://input', false, null, 0, 5_000_000) ?: '';
    Adms::handle($path, $_GET, $body)->send();
} catch (\Throwable $e) {
    App::log($e);
    Response::text('ERROR', 500)->send();
}
