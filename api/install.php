<?php
declare(strict_types=1);

/*
 * One-time web installer for shared hosting (Bluehost, Hostinger, any cPanel host).
 * Open https://YOUR-DOMAIN/api/install.php after uploading the files.
 * It needs the install key from api/INSTALL_KEY.txt (open it in cPanel › File Manager).
 * When finished it writes the config outside public_html and locks itself.
 */
require __DIR__ . '/src/bootstrap.php';

use App\Core\{Auth, Config, DB, Response};
use App\Services\{DemoSeed, Provision, Push};

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");
Response::$testMode = true; // no cookies are needed here

$keyFile = __DIR__ . '/INSTALL_KEY.txt';
$lockFile = __DIR__ . '/INSTALLED.lock';
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function page(string $title, string $body): never
{
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title><style>
body{font-family:system-ui,sans-serif;background:#0B0C0E;color:#F2F3F5;margin:0;padding:24px}main{max-width:720px;margin:0 auto}h1{font-size:26px}h2{font-size:18px;margin-top:28px}
label{display:block;font-size:13px;color:#9EA3AD;margin:12px 0 6px}input{width:100%;box-sizing:border-box;height:44px;border-radius:10px;border:1px solid #262930;background:#15171B;color:#F2F3F5;padding:0 12px;font-size:15px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}button{margin-top:24px;height:48px;padding:0 24px;border:0;border-radius:12px;background:#C8F031;color:#0B0C0E;font-weight:700;font-size:15px;cursor:pointer}
.ok{color:#C8F031}.bad{color:#FFB48C}.box{background:#15171B;border:1px solid #262930;border-radius:14px;padding:16px;margin:12px 0}code,pre{background:#1C1F24;padding:2px 6px;border-radius:6px;word-break:break-all;white-space:pre-wrap}
.check{display:flex;gap:8px;align-items:center;margin-top:12px}.check input{width:18px;height:18px}@media(max-width:600px){.grid{grid-template-columns:1fr}}</style></head><body><main>' . $body . '</main></body></html>';
    exit;
}

if (is_file($lockFile) || (Config::isInstalled() && !is_file($keyFile))) {
    http_response_code(404);
    page('Not found', '<h1>Already installed</h1><p>This installer is locked. Delete <code>api/INSTALLED.lock</code> only if you really need to run it again.</p>');
}
if (!is_file($keyFile)) page('Installer', '<h1>Install key missing</h1><p>Upload the complete package, including <code>api/INSTALL_KEY.txt</code>.</p>');
$installKey = trim((string)file_get_contents($keyFile));

// ---------- Requirements ----------
$checks = [
    'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'Sodium (encryption)' => function_exists('sodium_crypto_secretbox'),
    'OpenSSL' => extension_loaded('openssl'),
    'cURL (WhatsApp, Razorpay)' => extension_loaded('curl'),
    'Fileinfo (upload checks)' => extension_loaded('fileinfo'),
    'mbstring' => extension_loaded('mbstring'),
    'Uploads folder writable' => is_writable(__DIR__ . '/uploads') || @mkdir(__DIR__ . '/uploads', 0750, true),
];
$home = Config::homeDir();
$configPath = $home && is_writable($home) ? $home . '/gymapp-config-' . Config::siteKey() . '.php' : __DIR__ . '/config.php';
$checks['Config location writable'] = is_writable(dirname($configPath));
$allOk = !in_array(false, $checks, true);
$checkHtml = '<div class="box">' . implode('<br>', array_map(fn($k, $v) => ($v ? '<span class="ok">✓</span> ' : '<span class="bad">✗</span> ') . $k, array_keys($checks), $checks)) . '</div>';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$detectedUrl = $scheme . '://' . preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$f = array_merge(['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '', 'app_url' => $detectedUrl, 'admin_email' => '', 'admin_password' => '',
    'gym_name' => '', 'owner_name' => '', 'owner_email' => '', 'owner_password' => '', 'demo' => '1', 'install_key' => ''], array_map('strval', $_POST));
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($installKey, trim($f['install_key']))) throw new RuntimeException('The install key is wrong. Copy it from api/INSTALL_KEY.txt.');
        if (!$allOk) throw new RuntimeException('Fix the server requirements first (cPanel › MultiPHP Manager: choose PHP 8.2 or 8.3).');
        if (!filter_var($f['app_url'], FILTER_VALIDATE_URL)) throw new RuntimeException('Enter the site address, like https://gym.example.com');
        if (!filter_var($f['admin_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid platform admin email.');
        if ($err = Auth::checkPasswordStrength($f['admin_password'])) throw new RuntimeException("Platform admin password: $err.");
        if ($f['gym_name'] !== '') {
            if (!filter_var($f['owner_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid gym owner email.');
            if ($err = Auth::checkPasswordStrength($f['owner_password'])) throw new RuntimeException("Gym owner password: $err.");
        }
        // Connect and create tables.
        $cfg = [
            'db' => ['host' => $f['db_host'], 'port' => 3306, 'name' => $f['db_name'], 'user' => $f['db_user'], 'pass' => $f['db_pass']],
            'app_url' => rtrim($f['app_url'], '/'),
            'app_key' => bin2hex(random_bytes(32)),
            'cron_key' => bin2hex(random_bytes(16)),
            'demo_mode' => $f['demo'] === '1',
            'cookie_secure' => str_starts_with($f['app_url'], 'https://'),
            'debug' => false,
            'timezone' => 'Asia/Kolkata',
            'mail_from' => 'no-reply@' . parse_url($f['app_url'], PHP_URL_HOST),
            'upload_dir' => __DIR__ . '/uploads',
            'log_dir' => __DIR__ . '/logs',
            'vapid' => Push::generateKeys() + ['subject' => 'mailto:' . $f['admin_email']],
            'anthropic' => ['api_key' => null, 'model' => 'claude-haiku-5-5'],
            'client_ip_header' => null,
        ];
        Config::set($cfg);
        DB::reset();
        try { DB::pdo(); } catch (Throwable $ex) { throw new RuntimeException('Could not connect to the database: ' . $ex->getMessage()); }
        if (DB::val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$f['db_name'], 'gyms'])) {
            throw new RuntimeException('This database already has the tables. Use an empty database, or restore the existing config file.');
        }
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/db/schema.sql'));
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
            if ($stmt !== '') DB::pdo()->exec($stmt);
        }
        Provision::seedExercises();
        DB::insert('users', ['gym_id' => null, 'name' => 'Platform admin', 'email' => strtolower($f['admin_email']), 'password_hash' => Auth::hashPassword($f['admin_password']), 'role' => 'platform_admin']);
        $made = [];
        if ($f['demo'] === '1') { DemoSeed::run(); $made[] = 'Demo gym "FitZone Chennai" with sample data (owner@demo.gym / ' . DemoSeed::PASSWORD . ')'; }
        if ($f['gym_name'] !== '') {
            $g = Provision::createGym(['name' => $f['gym_name']], ['name' => $f['owner_name'] ?: 'Owner', 'email' => $f['owner_email'], 'password' => $f['owner_password']]);
            $made[] = 'Your gym "' . $f['gym_name'] . '" (owner ' . $f['owner_email'] . ')';
        }
        // Write config outside the web root when possible.
        $php = "<?php\n// Gym app configuration. Keep this file private.\nreturn " . var_export($cfg, true) . ";\n";
        if (file_put_contents($configPath, $php, LOCK_EX) === false) throw new RuntimeException("Could not write $configPath");
        @chmod($configPath, 0600);
        @file_put_contents($lockFile, date('c'));
        @unlink($keyFile);

        $u = $e($cfg['app_url']);
        $cronCmd = '/usr/local/bin/php ' . __DIR__ . '/cron.php > /dev/null 2>&1';
        page('Installed', '<h1 class="ok">Installed ✓</h1>
<div class="box"><b>Created</b><br>' . implode('<br>', array_map($e, $made ?: ['Platform admin only'])) . '</div>
<h2>1. Add the cron job (required)</h2><p>cPanel › Cron Jobs › Common settings: <b>Once per five minutes</b>. Command:</p><pre>' . $e($cronCmd) . '</pre>
<p>It sends WhatsApp reminders, closes open sessions at closing time, expires plans and scores churn.</p>
<h2>2. Sign in</h2><div class="box">Staff: <a class="ok" href="' . $u . '/login">' . $u . '/login</a><br>Platform console: sign in with ' . $e($f['admin_email']) . '<br>Member app: ' . $u . '/m/&lt;gym-slug&gt; · Website: ' . $u . '/g/&lt;gym-slug&gt;</div>
<h2>3. Connect services (per gym, in Settings › Integrations)</h2><p>WhatsApp Cloud API, Razorpay, SMS, AI assistant, lead ads, Strava. Until WhatsApp and Razorpay are connected, demo mode simulates messages and payments' . ($cfg['demo_mode'] ? '' : ' (demo mode is off, so they will show as not sent)') . '.</p>
<h2>4. Biometric devices</h2><p>Point eSSL/ZKTeco ADMS to <b>' . $e(parse_url($cfg['app_url'], PHP_URL_HOST)) . '</b>, port 443.</p>
<p>Config saved to <code>' . $e($configPath) . '</code>. The installer is now locked.</p>');
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$v = fn($k) => $e($f[$k]);
page('Install', '<h1>Install the gym app</h1>
<p>Create an empty MySQL database and user first: cPanel › MySQL Database Wizard (give the user ALL PRIVILEGES).</p>' . $checkHtml .
($error ? '<div class="box bad">' . $e($error) . '</div>' : '') . '
<form method="post" autocomplete="off">
<label>Install key (from api/INSTALL_KEY.txt)</label><input name="install_key" value="' . $v('install_key') . '" required>
<h2>Database</h2><div class="grid">
<div><label>Host</label><input name="db_host" value="' . $v('db_host') . '" required></div>
<div><label>Database name</label><input name="db_name" value="' . $v('db_name') . '" required placeholder="cpaneluser_gym"></div>
<div><label>Database user</label><input name="db_user" value="' . $v('db_user') . '" required></div>
<div><label>Database password</label><input name="db_pass" type="password" value="' . $v('db_pass') . '"></div></div>
<h2>Site</h2><label>Site address</label><input name="app_url" value="' . $v('app_url') . '" required>
<h2>Platform admin (you, the software owner)</h2><div class="grid">
<div><label>Email</label><input name="admin_email" type="email" value="' . $v('admin_email') . '" required></div>
<div><label>Password (10+ characters, letters and numbers)</label><input name="admin_password" type="password" required></div></div>
<h2>First gym (optional, you can also add gyms later in the platform console)</h2><div class="grid">
<div><label>Gym name</label><input name="gym_name" value="' . $v('gym_name') . '"></div>
<div><label>Owner name</label><input name="owner_name" value="' . $v('owner_name') . '"></div>
<div><label>Owner email</label><input name="owner_email" type="email" value="' . $v('owner_email') . '"></div>
<div><label>Owner password</label><input name="owner_password" type="password"></div></div>
<input type="hidden" name="demo" value="0"><label class="check"><input type="checkbox" name="demo" value="1"' . ($f['demo'] === '1' ? ' checked' : '') . '> Create the demo gym with sample data and turn on demo mode (simulated WhatsApp and payments until real keys are added)</label>
<button type="submit">Install</button></form>');
