<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use App\App;
use App\Core\Request;

// Everything under /api/ is routed here by .htaccess.
$req = Request::fromGlobals('/api');
App::handle($req)->send();
