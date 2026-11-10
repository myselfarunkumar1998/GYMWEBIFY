<?php
declare(strict_types=1);

/*
 * Scheduled jobs. In cPanel › Cron Jobs add (every 5 minutes):
 *   /usr/local/bin/php /home/USER/public_html/api/cron.php >/dev/null 2>&1
 * It can also be triggered over HTTPS with the cron key: /api/cron.php?key=CRON_KEY
 */
require __DIR__ . '/src/bootstrap.php';

use App\App;
use App\Controllers\CampaignsController;
use App\Core\Config;
use App\Services\Cron;

if (PHP_SAPI !== 'cli') {
    $key = (string)($_GET['key'] ?? '');
    if (!Config::get('cron_key') || !hash_equals((string)Config::get('cron_key'), $key)) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: application/json');
}
try {
    $force = in_array('--force', $argv ?? [], true);
    $log = Cron::run($force);
    $log['campaigns_sent'] = CampaignsController::sendDue();
    echo json_encode($log, JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    App::log($e);
    echo 'Cron failed: ' . $e->getMessage() . "\n";
    exit(1);
}
