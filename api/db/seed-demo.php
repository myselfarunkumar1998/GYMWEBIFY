<?php
// CLI: php api/db/seed-demo.php  — (re)creates the demo gym with sample data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../src/bootstrap.php';
\App\Core\Response::$testMode = true;
$t = microtime(true);
print_r(\App\Services\DemoSeed::run());
printf("Done in %.1fs\n", microtime(true) - $t);
