<?php
declare(strict_types=1);

/**
 * API integration + security tests over real HTTP against a throwaway database.
 *   php tests/api/run.php            (expects the test server on http://localhost:8090)
 * The runner resets the gymapp_test database, seeds the demo gym and a second gym, then runs every test.
 */

const BASE = 'http://localhost:8090';
$ROOT = dirname(__DIR__, 2);
putenv('GYM_DB=gymapp_test');
putenv('GYM_URL=' . BASE);

// ---------- tiny HTTP client with its own cookie jar ----------
final class Client
{
    public array $cookies = [];
    public array $lastHeaders = [];
    public function __construct(public string $ip = '') {}

    public function req(string $method, string $path, $body = null, array $headers = [], bool $csrf = true): array
    {
        $ch = curl_init(BASE . $path);
        $h = ['Accept: application/json'];
        if ($csrf) $h[] = 'X-Requested-With: gymapp';
        if ($body !== null && !is_string($body)) { $h[] = 'Content-Type: application/json'; $body = json_encode($body); }
        if ($this->cookies) $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        if ($this->ip) $h[] = 'X-Test-Ip: ' . $this->ip;
        $respHeaders = [];
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array_merge($h, $headers), CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                if (stripos($line, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
                    if ($m[2] === '' || $m[2] === 'deleted') unset($this->cookies[$m[1]]); else $this->cookies[$m[1]] = $m[2];
                }
                $respHeaders[] = trim($line);
                return strlen($line);
            }]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $this->lastHeaders = $respHeaders;
        $json = json_decode((string)$raw, true);
        return ['status' => $code, 'data' => $json['data'] ?? null, 'error' => $json['error'] ?? null, 'raw' => (string)$raw];
    }
    public function get(string $p, array $q = []): array { return $this->req('GET', $p . ($q ? '?' . http_build_query($q) : '')); }
    public function post(string $p, $b = []): array { return $this->req('POST', $p, $b); }
    public function put(string $p, $b = []): array { return $this->req('PUT', $p, $b); }
    public function del(string $p): array { return $this->req('DELETE', $p); }
    public function login(string $email, string $pass = 'Demo@12345'): self
    {
        $r = $this->post('/api/auth/login', ['email' => $email, 'password' => $pass]);
        if ($r['status'] !== 200) throw new RuntimeException("login $email failed: " . $r['raw']);
        return $this;
    }
}

// ---------- assertions ----------
$pass = 0; $fail = 0; $failures = []; $current = '';
function ok(bool $cond, string $msg, $detail = null): void
{
    global $pass, $fail, $failures, $current;
    if ($cond) { $pass++; return; }
    $fail++;
    $failures[] = "[$current] $msg" . ($detail !== null ? ' :: ' . substr(is_string($detail) ? $detail : json_encode($detail), 0, 300) : '');
}
function eq($a, $b, string $msg): void { ok($a == $b, $msg . " (expected " . json_encode($b) . ", got " . json_encode($a) . ")"); }
function status(array $r, int $code, string $msg): void { ok($r['status'] === $code, "$msg → HTTP {$r['status']} (expected $code)", $r['raw']); }
function test(string $name, callable $fn): void
{
    global $current, $fail, $failures;
    $current = $name;
    try { $fn(); } catch (Throwable $e) { $fail++; $failures[] = "[$name] EXCEPTION " . $e->getMessage() . ' @' . $e->getLine(); }
}
function db(): PDO
{
    static $pdo;
    if (!$pdo) { $pdo = new PDO('mysql:host=127.0.0.1;dbname=gymapp_test;charset=utf8mb4', 'gym', 'gympass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); $pdo->exec("SET time_zone = '+05:30'"); }
    return $pdo;
}
function q(string $sql, array $p = []): array { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function q1(string $sql, array $p = []) { $r = q($sql, $p); return $r ? reset($r[0]) : null; }

// ---------- reset database ----------
echo "Resetting test database…\n";
$root = new PDO('mysql:host=127.0.0.1', 'gym', 'gympass');
$root->exec('DROP DATABASE IF EXISTS gymapp_test');
$root->exec('CREATE DATABASE gymapp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
db()->exec(file_get_contents("$ROOT/api/db/schema.sql"));
passthru("GYM_DB=gymapp_test GYM_URL=" . BASE . " php $ROOT/api/db/seed-demo.php > /dev/null", $rc);
if ($rc !== 0) exit("Seeding failed\n");
// Second tenant for isolation tests
passthru("GYM_DB=gymapp_test php -r " . escapeshellarg('require "' . $ROOT . '/api/src/bootstrap.php"; \App\Core\Response::$testMode = true; $r = \App\Services\Provision::createGym(["name" => "Other Gym"], ["name" => "Other Owner", "email" => "owner@other.gym", "password" => "Other@12345"]); echo json_encode($r);') . ' > /tmp/othergym.json');
$other = json_decode(file_get_contents('/tmp/othergym.json'), true);
$gymA = (int)q1("SELECT id FROM gyms WHERE slug = 'demo'");
db()->exec('DELETE FROM rate_limits');

$owner = (new Client('10.0.0.1'))->login('owner@demo.gym');
$desk = (new Client('10.0.0.2'))->login('desk@demo.gym');
$trainer = (new Client('10.0.0.3'))->login('trainer@demo.gym');
$otherOwner = (new Client('10.0.0.4'))->login('owner@other.gym', 'Other@12345');
$anon = new Client('10.0.0.5');
$plan = fn(string $n) => (int)q1('SELECT id FROM plans WHERE gym_id = ? AND name = ?', [$gymA, $n]);
$karthik = (int)q1("SELECT id FROM members WHERE gym_id = ? AND phone = '9876543210'", [$gymA]);
$t0 = microtime(true);

// ================= SECURITY =================

test('auth: bad credentials are generic and lock the account after 5 tries', function () {
    $c = new Client('10.1.0.1');
    $r1 = $c->post('/api/auth/login', ['email' => 'nobody@demo.gym', 'password' => 'x']);
    $r2 = $c->post('/api/auth/login', ['email' => 'manager@demo.gym', 'password' => 'wrong-pass']);
    status($r1, 401, 'unknown email'); status($r2, 401, 'wrong password');
    eq($r1['error']['message'], $r2['error']['message'], 'same message for unknown email and wrong password');
    for ($i = 0; $i < 4; $i++) $c->post('/api/auth/login', ['email' => 'manager@demo.gym', 'password' => 'wrong-pass']);
    $r = $c->post('/api/auth/login', ['email' => 'manager@demo.gym', 'password' => 'Demo@12345']);
    status($r, 423, 'locked after 5 failures even with the right password');
    q('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE email = ?', ['manager@demo.gym']);
});

test('auth: session cookie is HttpOnly + SameSite and the token is stored hashed', function () {
    $c = new Client('10.1.0.2');
    $c->post('/api/auth/login', ['email' => 'owner@demo.gym', 'password' => 'Demo@12345']);
    $line = implode("\n", array_filter($c->lastHeaders, fn($h) => stripos($h, 'Set-Cookie: gs_staff=') === 0));
    ok(stripos($line, 'HttpOnly') !== false, 'HttpOnly flag', $line);
    ok(stripos($line, 'SameSite=Lax') !== false, 'SameSite flag', $line);
    $token = $c->cookies['gs_staff'];
    eq((int)q1('SELECT COUNT(*) FROM auth_sessions WHERE token_hash = ?', [$token]), 0, 'raw token not stored');
    eq((int)q1('SELECT COUNT(*) FROM auth_sessions WHERE token_hash = ?', [hash('sha256', $token)]), 1, 'hash stored');
    $c->post('/api/auth/logout');
    status($c->get('/api/auth/me'), 401, 'logout ends session');
});

test('auth: unauthenticated and tampered cookies are rejected', function () use ($anon) {
    status($anon->get('/api/members'), 401, 'no cookie');
    $c = new Client(); $c->cookies['gs_staff'] = str_repeat('A', 43);
    status($c->get('/api/members'), 401, 'forged token');
    $c->cookies['gs_staff'] = "' OR 1=1 --";
    status($c->get('/api/members'), 401, 'injection in cookie');
});

test('csrf: state changes need the custom header and a same-site Origin', function () use ($owner) {
    status($owner->req('POST', '/api/leads', ['name' => 'X', 'phone' => '9000000001'], [], false), 403, 'no X-Requested-With header');
    status($owner->req('POST', '/api/leads', ['name' => 'X', 'phone' => '9000000001'], ['Origin: https://evil.example']), 403, 'foreign Origin');
    status($owner->req('POST', '/api/leads', ['name' => 'CSRF ok', 'phone' => '9000000002'], ['Origin: ' . BASE]), 200, 'same Origin allowed');
});

test('tenant isolation: another gym cannot read or change this gym\'s data', function () use ($otherOwner, $karthik, $gymA) {
    $inv = (int)q1('SELECT id FROM invoices WHERE gym_id = ? LIMIT 1', [$gymA]);
    $lead = (int)q1('SELECT id FROM leads WHERE gym_id = ? LIMIT 1', [$gymA]);
    $cls = (int)q1('SELECT id FROM classes WHERE gym_id = ? LIMIT 1', [$gymA]);
    $ms = (int)q1('SELECT id FROM memberships WHERE gym_id = ? LIMIT 1', [$gymA]);
    status($otherOwner->get("/api/members/$karthik"), 404, 'member');
    status($otherOwner->put("/api/members/$karthik", ['name' => 'Hacked']), 404, 'update member');
    status($otherOwner->get("/api/invoices/$inv"), 404, 'invoice');
    status($otherOwner->get("/api/invoices/$inv/print"), 404, 'invoice print');
    status($otherOwner->post("/api/invoices/$inv/payments", ['amount' => 1, 'mode' => 'cash']), 404, 'pay invoice');
    status($otherOwner->get("/api/leads/$lead"), 404, 'lead');
    status($otherOwner->get("/api/classes/$cls/bookings"), 404, 'class');
    status($otherOwner->post("/api/memberships/$ms/freeze", ['from' => date('Y-m-d'), 'days' => 7]), 404, 'membership');
    status($otherOwner->post('/api/sessions/checkin', ['member_id' => $karthik]), 404, 'check in another gym\'s member');
    $list = $otherOwner->get('/api/members', ['q' => 'Karthik']);
    eq($list['data']['total'], 0, 'search does not leak');
    eq(q1('SELECT name FROM members WHERE id = ?', [$karthik]), 'Karthik Subramaniam', 'data unchanged');
    $branchA = (int)q1('SELECT id FROM branches WHERE gym_id = ? LIMIT 1', [$gymA]);
    status($otherOwner->get('/api/dashboard', ['branch_id' => $branchA]), 404, 'cannot query another gym\'s branch');
});

test('rbac: roles only reach what they are allowed to', function () use ($desk, $trainer, $karthik, $gymA) {
    status($trainer->get('/api/billing/summary'), 403, 'trainer → billing');
    status($trainer->get('/api/settings'), 403, 'trainer → settings');
    $notMine = (int)q1('SELECT id FROM members WHERE gym_id = ? AND (trainer_id IS NULL OR trainer_id <> (SELECT id FROM users WHERE email = ?)) LIMIT 1', [$gymA, 'trainer@demo.gym']);
    status($trainer->get("/api/members/$notMine"), 403, 'trainer → member not assigned');
    status($trainer->get("/api/members/$karthik"), 200, 'trainer → own client');
    $list = $trainer->get('/api/members', ['limit' => 200]);
    $tid = (int)q1('SELECT id FROM users WHERE email = ?', ['trainer@demo.gym']);
    ok(count(array_filter($list['data']['items'], fn($m) => (int)$m['trainer_id'] !== $tid)) === 0, 'trainer list only has own clients');
    status($desk->put('/api/settings/integrations', ['razorpay_key_id' => 'x']), 403, 'front desk → integrations');
    status($desk->post('/api/payroll/run', ['period' => '2026-10']), 403, 'front desk → payroll');
    status($desk->get('/api/reports'), 403, 'front desk → reports');
    status($desk->post('/api/invoices/1/cancel', ['reason' => 'x']), 403, 'front desk → cancel invoice');
    status($desk->del("/api/members/$karthik"), 403, 'front desk → delete member');
    $s = $desk->get('/api/staff');
    ok(!isset($s['data']['items'][0]['salary']), 'front desk cannot see salaries');
    status($desk->get('/api/platform/gyms'), 403, 'staff → platform console');
});

test('member vs staff sessions are separate', function () use ($owner, $karthik) {
    status($owner->get('/api/m/home'), 401, 'staff cookie on member API');
    $m = new Client('10.2.0.1');
    $o = $m->post('/api/m/auth/otp', ['gym' => 'demo', 'phone' => '9876543210']);
    $m->post('/api/m/auth/verify', ['gym' => 'demo', 'phone' => '9876543210', 'code' => $o['data']['demo_code']]);
    status($m->get('/api/m/home'), 200, 'member signed in');
    status($m->get('/api/members'), 401, 'member cookie on staff API');
    status($m->get('/api/m/invoices/' . (int)q1('SELECT id FROM invoices WHERE member_id <> ? LIMIT 1', [$karthik]) . '/print'), 404, 'member cannot open another member\'s invoice');
});

test('otp: wrong codes are limited and unknown numbers are not revealed', function () {
    $c = new Client('10.2.0.2');
    $unknown = $c->post('/api/m/auth/otp', ['gym' => 'demo', 'phone' => '9000099999']);
    status($unknown, 200, 'unknown phone still 200');
    ok(!isset($unknown['data']['demo_code']), 'no code for unknown phone');
    $c->post('/api/m/auth/otp', ['gym' => 'demo', 'phone' => '9876500455']);
    for ($i = 0; $i < 5; $i++) $c->post('/api/m/auth/verify', ['gym' => 'demo', 'phone' => '9876500455', 'code' => '000000']);
    $real = q1("SELECT id FROM otp_codes WHERE phone = '9876500455' ORDER BY id DESC LIMIT 1");
    eq((int)q1('SELECT attempts FROM otp_codes WHERE id = ?', [$real]), 5, 'attempts counted');
    $r = $c->post('/api/m/auth/verify', ['gym' => 'demo', 'phone' => '9876500455', 'code' => '000000']);
    status($r, 422, 'code locked after 5 wrong tries');
});

test('rate limiting: login floods get 429', function () {
    db()->exec("DELETE FROM rate_limits WHERE rl_key LIKE 'login:%'");
    $c = new Client('10.3.0.1'); $last = null;
    for ($i = 0; $i < 22; $i++) $last = $c->post('/api/auth/login', ['email' => "flood$i@x.com", 'password' => 'x']);
    status($last, 429, '21st attempt from one IP');
});

test('injection: search and sort parameters are safe', function () use ($owner) {
    foreach (["' OR '1'='1", "\" OR 1=1 --", "%' UNION SELECT password_hash FROM users --", "1; DROP TABLE members"] as $q) {
        $r = $owner->get('/api/members', ['q' => $q]);
        status($r, 200, "q=$q");
        eq($r['data']['total'], 0, 'no rows for injection string');
    }
    status($owner->get('/api/members', ['sort' => 'name; DROP TABLE members']), 200, 'sort whitelist');
    ok((int)q1('SELECT COUNT(*) FROM members') > 100, 'members table intact');
    status($owner->get('/api/members/' . rawurlencode('1 OR 1=1')), 404, 'non-numeric id');
});

test('mass assignment: protected columns cannot be set through the API', function () use ($owner, $karthik, $gymA) {
    $owner->put("/api/members/$karthik", ['name' => 'Karthik Subramaniam', 'gym_id' => 999, 'credit_balance' => 99999, 'qr_secret' => 'x']);
    eq((int)q1('SELECT gym_id FROM members WHERE id = ?', [$karthik]), $gymA, 'gym_id unchanged');
    ok((float)q1('SELECT credit_balance FROM members WHERE id = ?', [$karthik]) < 99999, 'credit unchanged');
    ok(q1('SELECT qr_secret FROM members WHERE id = ?', [$karthik]) !== 'x', 'qr_secret unchanged');
    $r = $owner->get("/api/members/$karthik");
    ok(!array_key_exists('qr_secret', $r['data']), 'qr_secret never returned');
});

test('uploads: only real images/PDFs, size-limited, served only to the right gym', function () use ($owner, $otherOwner, $karthik) {
    $php = 'data:image/png;base64,' . base64_encode("<?php system(\$_GET['c']); ?>");
    status($owner->post("/api/members/$karthik/upload/photo", ['file' => $php]), 422, 'PHP disguised as PNG');
    $svg = 'data:image/svg+xml;base64,' . base64_encode('<svg onload="alert(1)"/>');
    status($owner->post("/api/members/$karthik/upload/photo", ['file' => $svg]), 422, 'SVG rejected');
    $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    status($owner->post("/api/members/$karthik/upload/photo", ['file' => $png]), 200, 'real PNG accepted');
    $path = q1('SELECT photo_path FROM members WHERE id = ?', [$karthik]);
    ok((bool)preg_match('#^\d+/\d{4}/\d{2}/[a-f0-9]{32}\.png$#', (string)$path), 'random file name', $path);
    status($owner->get("/api/members/$karthik/file/photo"), 200, 'owner can view');
    status($otherOwner->get("/api/members/$karthik/file/photo"), 404, 'other gym cannot view');
    status($owner->get("/api/members/$karthik/file/..%2F..%2Fconfig.php"), 404, 'path traversal');
    $big = 'data:image/png;base64,' . base64_encode(str_repeat('A', 6_000_000));
    status($owner->post("/api/members/$karthik/upload/photo", ['file' => $big]), 422, 'oversize rejected');
});

test('ssrf: webhooks cannot target private networks', function () use ($owner) {
    status($owner->post('/api/webhooks', ['url' => 'https://127.0.0.1/hook', 'events' => ['checkin']]), 422, 'loopback');
    status($owner->post('/api/webhooks', ['url' => 'https://169.254.169.254/latest', 'events' => ['checkin']]), 422, 'cloud metadata');
    status($owner->post('/api/webhooks', ['url' => 'http://example.com/hook', 'events' => ['checkin']]), 422, 'plain http');
});

test('incoming webhooks verify signatures', function () use ($anon, $gymA) {
    status($anon->req('POST', "/api/hooks/razorpay/$gymA", '{"event":"payment_link.paid"}', ['Content-Type: application/json', 'X-Razorpay-Signature: bad'], false), 403, 'razorpay bad signature');
    status($anon->req('POST', "/api/hooks/whatsapp/$gymA", '{"entry":[]}', ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=bad'], false), 403, 'whatsapp bad signature');
    status($anon->req('GET', "/api/hooks/whatsapp/$gymA?hub.mode=subscribe&hub.verify_token=guess&hub.challenge=1", null, [], false), 403, 'whatsapp verify token');
    status($anon->req('POST', "/api/hooks/google-leads/$gymA", '{"google_key":"guess"}', ['Content-Type: application/json'], false), 403, 'google lead key');
});

test('encrypted secrets: integration keys are stored encrypted and returned masked', function () use ($owner, $gymA) {
    $owner->put('/api/settings/integrations', ['razorpay_key_id' => 'rzp_test_abc', 'razorpay_key_secret' => 'supersecretvalue1234']);
    $raw = q1('SELECT settings FROM gyms WHERE id = ?', [$gymA]);
    ok(!str_contains($raw, 'supersecretvalue1234'), 'secret not in plaintext');
    $s = $owner->get('/api/settings');
    eq($s['data']['integrations']['razorpay_key_secret'], '••••1234', 'masked');
    $owner->put('/api/settings/integrations', ['razorpay_key_id' => null, 'razorpay_key_secret' => null]);
});

test('output safety: invoice HTML escapes names, CSV neutralises formulas', function () use ($owner) {
    $r = $owner->post('/api/members', ['name' => '<script>alert(1)</script>', 'phone' => '9111111111']);
    $id = $r['data']['id'];
    $inv = $owner->post('/api/invoices', ['member_id' => $id, 'items' => [['description' => '=HYPERLINK("http://x")', 'rate' => 100]]]);
    $html = $owner->get("/api/invoices/{$inv['data']['id']}/print")['raw'];
    ok(!str_contains($html, '<script>alert(1)</script>'), 'script tag escaped');
    ok(str_contains($html, '&lt;script&gt;'), 'escaped form present');
    $owner->put("/api/members/$id", ['name' => '=cmd|calc!A1']);
    $csv = $owner->get('/api/members/export', ['q' => 'cmd'])['raw'];
    ok(str_contains($csv, "'=cmd") , 'formula prefixed with quote', $csv);
});

test('security headers on API responses', function () use ($owner) {
    $owner->get('/api/auth/me');
    $h = implode("\n", $owner->lastHeaders);
    ok(stripos($h, 'X-Content-Type-Options: nosniff') !== false, 'nosniff');
    ok(stripos($h, 'Cache-Control: no-store') !== false, 'no-store');
});

test('password reset tokens are single-use and expire', function () use ($gymA) {
    $c = new Client('10.4.0.1');
    status($c->post('/api/auth/forgot', ['email' => 'nobody@nowhere.com']), 200, 'unknown email still 200');
    $c->post('/api/auth/forgot', ['email' => 'kavya@demo.gym']);
    $token = 'testtoken_' . bin2hex(random_bytes(10)) . 'abcdefghijklm';
    $uid = (int)q1('SELECT id FROM users WHERE email = ?', ['kavya@demo.gym']);
    q('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)', [$uid, hash('sha256', $token)]);
    status($c->post('/api/auth/reset', ['token' => $token, 'password' => 'short']), 422, 'weak password rejected');
    status($c->post('/api/auth/reset', ['token' => $token, 'password' => 'NewPass12345']), 200, 'reset works');
    status($c->post('/api/auth/reset', ['token' => $token, 'password' => 'NewPass12345x']), 422, 'token reused');
    (new Client('10.4.0.2'))->login('kavya@demo.gym', 'NewPass12345');
    ok(true, 'login with new password');
});

// ================= BUSINESS FLOWS =================

test('new member with plan: GST invoice, payment, active access, welcome', function () use ($owner, $plan) {
    $r = $owner->post('/api/members', ['name' => 'Flow Test', 'phone' => '9222222222', 'plan_id' => $plan('Quarterly'), 'pay_amount' => 5310, 'pay_mode' => 'upi']);
    status($r, 200, 'created');
    $inv = q('SELECT * FROM invoices WHERE id = ?', [$r['data']['invoice_id']])[0];
    eq((float)$inv['subtotal'], 4500.0, 'plan 4000 + joining fee 500');
    eq((float)$inv['cgst'], 405.0, 'CGST 9%'); eq((float)$inv['sgst'], 405.0, 'SGST 9%'); eq((float)$inv['igst'], 0.0, 'no IGST intra-state');
    eq((float)$inv['total'], 5310.0, 'total'); eq($inv['status'], 'paid', 'paid');
    ok((bool)preg_match('#^INV/\d{4}-\d{2}/\d{4}$#', $inv['invoice_no']), 'invoice number format', $inv['invoice_no']);
    $m = $owner->get('/api/members/' . $r['data']['id']);
    eq($m['data']['plan_status'], 'active', 'plan active'); ok($m['data']['access']['allowed'], 'door access allowed');
    ok((int)q1("SELECT COUNT(*) FROM messages WHERE member_id = ? AND tkey = 'welcome'", [$r['data']['id']]) === 1, 'welcome queued');
    ok((int)q1("SELECT COUNT(*) FROM device_commands WHERE command LIKE ?", ['%PIN=' . $m['data']['device_user_id'] . "\t%"]) >= 1, 'pushed to door devices');
    status($owner->post('/api/members', ['name' => 'Dup', 'phone' => '9222222222']), 422, 'duplicate phone blocked');
});

test('renewal starts after the current plan; partial payment leaves dues; collecting clears them', function () use ($owner, $plan) {
    $id = (int)q1("SELECT id FROM members WHERE phone = '9222222222'");
    $cur = q("SELECT end_date FROM memberships WHERE member_id = ? AND kind = 'membership' ORDER BY id DESC LIMIT 1", [$id])[0]['end_date'];
    $r = $owner->post("/api/members/$id/memberships", ['plan_id' => $plan('Monthly'), 'pay_amount' => 1000, 'pay_mode' => 'cash']);
    $ms = q('SELECT * FROM memberships WHERE id = ?', [$r['data']['membership_id']])[0];
    eq($ms['start_date'], date('Y-m-d', strtotime("$cur +1 day")), 'starts day after current end');
    eq($ms['status'], 'upcoming', 'upcoming');
    $inv = q('SELECT * FROM invoices WHERE id = ?', [$r['data']['invoice_id']])[0];
    eq((float)$inv['total'], 1770.0, 'no joining fee on renewal'); eq($inv['status'], 'partial', 'partial'); eq((float)$inv['balance'], 770.0, 'balance');
    status($owner->post("/api/invoices/{$inv['id']}/payments", ['amount' => 800, 'mode' => 'cash']), 422, 'over-payment blocked');
    $p = $owner->post("/api/invoices/{$inv['id']}/payments", ['amount' => 770, 'mode' => 'upi', 'reference' => 'UPI123']);
    eq($p['data']['invoice']['status'], 'paid', 'cleared');
});

test('coupons and referral credit', function () use ($owner, $plan, $karthik) {
    $r = $owner->post('/api/members', ['name' => 'Coupon User', 'phone' => '9333333333', 'referred_by' => $karthik, 'plan_id' => $plan('Monthly'), 'coupon' => 'FRIEND500', 'pay_amount' => 0]);
    $inv = q('SELECT * FROM invoices WHERE id = ?', [$r['data']['invoice_id']])[0];
    eq((float)$inv['discount'], 500.0, 'coupon discount'); eq($inv['coupon_code'], 'FRIEND500', 'coupon code saved');
    eq((float)q1('SELECT credit_balance FROM members WHERE id = ?', [$r['data']['id']]), 500.0, 'new member got referral credit');
    status($owner->post("/api/members/{$r['data']['id']}/memberships", ['plan_id' => $plan('Monthly'), 'coupon' => 'NOPE']), 422, 'bad coupon');
    $pay = $owner->post("/api/invoices/{$inv['id']}/payments", ['amount' => 500, 'mode' => 'credit']);
    status($pay, 200, 'pay with referral credit');
    eq((float)q1('SELECT credit_balance FROM members WHERE id = ?', [$r['data']['id']]), 0.0, 'credit used');
});

test('freeze moves the end date and blocks the door; unfreeze gives days back', function () use ($owner, $desk) {
    $id = (int)q1("SELECT id FROM members WHERE phone = '9222222222'");
    $ms = q("SELECT * FROM memberships WHERE member_id = ? AND status = 'active' AND kind = 'membership'", [$id])[0];
    status($owner->post("/api/memberships/{$ms['id']}/freeze", ['from' => date('Y-m-d'), 'days' => 3]), 422, 'below minimum days');
    status($owner->post("/api/memberships/{$ms['id']}/freeze", ['from' => date('Y-m-d'), 'days' => 50]), 422, 'more than allowed');
    $r = $owner->post("/api/memberships/{$ms['id']}/freeze", ['from' => date('Y-m-d'), 'days' => 7]);
    status($r, 200, 'frozen');
    eq(q1('SELECT end_date FROM memberships WHERE id = ?', [$ms['id']]), date('Y-m-d', strtotime($ms['end_date'] . ' +7 days')), 'end +7');
    $c = $desk->post('/api/sessions/checkin', ['member_id' => $id]);
    eq($c['data']['result'], 'denied', 'door denied while frozen');
    ok(str_contains((string)$c['data']['reason'], 'frozen'), 'reason says frozen');
    $owner->post("/api/memberships/{$ms['id']}/unfreeze");
    eq(q1('SELECT end_date FROM memberships WHERE id = ?', [$ms['id']]), $ms['end_date'], 'end date restored');
});

test('check-in sessions: IN, duplicate ignored, OUT with duration, expired denied', function () use ($desk, $gymA) {
    $id = (int)q1("SELECT id FROM members WHERE phone = '9222222222'");
    $in = $desk->post('/api/sessions/checkin', ['member_id' => $id]);
    eq($in['data']['result'], 'in', 'IN');
    $dup = $desk->post('/api/sessions/checkin', ['member_id' => $id]);
    eq($dup['data']['result'], 'ignored', 'duplicate within 90s ignored');
    q('UPDATE attendance_sessions SET in_at = NOW() - INTERVAL 75 MINUTE WHERE id = ?', [$in['data']['session_id']]);
    $out = $desk->post('/api/sessions/checkin', ['member_id' => $id]);
    eq($out['data']['result'], 'out', 'OUT');
    eq((int)$out['data']['duration_min'], 75, 'duration 75 min');
    $expired = (int)q1("SELECT id FROM members WHERE gym_id = ? AND phone = '9876500573'", [$gymA]);
    $d = $desk->post('/api/sessions/checkin', ['member_id' => $expired]);
    eq($d['data']['result'], 'denied', 'expired member denied');
});

test('QR check-in: valid rotating token works, forged or old tokens fail', function () use ($desk, $karthik) {
    $m = new Client('10.5.0.1');
    $o = $m->post('/api/m/auth/otp', ['gym' => 'demo', 'phone' => '9876543210']);
    $m->post('/api/m/auth/verify', ['gym' => 'demo', 'phone' => '9876543210', 'code' => $o['data']['demo_code']]);
    $tok = $m->get('/api/m/card')['data']['token'];
    q('DELETE FROM attendance_sessions WHERE member_id = ? AND DATE(in_at) = CURDATE()', [$karthik]);
    $r = $desk->post('/api/sessions/checkin', ['qr' => $tok]);
    ok(in_array($r['data']['result'] ?? '', ['in', 'out'], true), 'QR accepted', $r['raw']);
    $forged = preg_replace('/[a-f0-9]{20}$/', str_repeat('a', 20), $tok);
    status($desk->post('/api/sessions/checkin', ['qr' => $forged]), 422, 'forged HMAC');
    [$pfx, $mid, $w] = explode('.', $tok);
    status($desk->post('/api/sessions/checkin', ['qr' => "GM1.$mid." . ((int)$w - 10) . '.' . str_repeat('b', 20)]), 422, 'old window');
});

test('eSSL/ZKTeco ADMS push: handshake, punches pair into sessions, commands delivered', function () use ($anon, $gymA) {
    $sn = q1('SELECT serial_no FROM devices WHERE gym_id = ? LIMIT 1', [$gymA]);
    status($anon->req('GET', '/iclock/cdata?SN=UNKNOWN&options=all', null, [], false), 403, 'unknown device rejected');
    $h = $anon->req('GET', "/iclock/cdata?SN=$sn&options=all", null, [], false);
    ok(str_contains($h['raw'], 'ATTLOGStamp'), 'handshake options', $h['raw']);
    $pin = q1("SELECT device_user_id FROM members WHERE phone = '9333333333'");
    $mid = (int)q1("SELECT id FROM members WHERE phone = '9333333333'");
    $t1 = date('Y-m-d H:i:s', time() - 3600); $t2 = date('Y-m-d H:i:s', time() - 600);
    $body = "$pin\t$t1\t0\t1\t0\t0\n$pin\t$t2\t1\t1\t0\t0\n99999\t$t2\t0\t1\t0\t0\n";
    $r = $anon->req('POST', "/iclock/cdata?SN=$sn&table=ATTLOG&Stamp=1", $body, ['Content-Type: text/plain'], false);
    ok(str_starts_with($r['raw'], 'OK'), 'ATTLOG accepted', $r['raw']);
    $s = q('SELECT * FROM attendance_sessions WHERE member_id = ? ORDER BY id DESC LIMIT 1', [$mid])[0] ?? null;
    ok($s && $s['out_at'] !== null && (int)$s['duration_min'] === 50, 'IN/OUT paired with 50 min', $s);
    ok((int)q1("SELECT COUNT(*) FROM punch_log WHERE device_user_id = '99999' AND result = 'denied'") === 1, 'unknown finger logged as denied');
    $again = $anon->req('POST', "/iclock/cdata?SN=$sn&table=ATTLOG", $body, ['Content-Type: text/plain'], false);
    eq((int)q1('SELECT COUNT(*) FROM attendance_sessions WHERE member_id = ?', [$mid]), 1, 'resent punches do not duplicate');
    $cmd = $anon->req('GET', "/iclock/getrequest?SN=$sn", null, [], false);
    ok(str_starts_with($cmd['raw'], 'C:') || $cmd['raw'] === 'OK', 'command poll', $cmd['raw']);
    if (preg_match('/^C:(\d+):/', $cmd['raw'], $mm)) {
        $anon->req('POST', "/iclock/devicecmd?SN=$sn", "ID={$mm[1]}&Return=0&CMD=DATA", ['Content-Type: text/plain'], false);
        eq(q1('SELECT status FROM device_commands WHERE id = ?', [$mm[1]]), 'done', 'command acknowledged');
    }
});

test('classes: capacity, waitlist and promotion on cancel', function () use ($owner, $gymA) {
    $date = date('Y-m-d', strtotime('+7 days'));
    $dow = (int)date('w', strtotime($date));
    $branch = (int)q1('SELECT id FROM branches WHERE gym_id = ? ORDER BY id LIMIT 1', [$gymA]);
    $c = $owner->post('/api/classes', ['name' => 'Tiny', 'branch_id' => $branch, 'day_of_week' => $dow, 'start_time' => '10:00', 'duration_min' => 45, 'capacity' => 2]);
    $cid = $c['data']['id'];
    $ms = q("SELECT DISTINCT m.id FROM members m JOIN memberships ms ON ms.member_id = m.id WHERE m.gym_id = ? AND ms.kind = 'membership' AND ms.status = 'active' AND ms.end_date > ? AND (m.branch_id = ? OR m.branch_id IS NULL) AND m.is_blocked = 0 AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.member_id = m.id AND i.status IN ('unpaid','partial')) LIMIT 3", [$gymA, date('Y-m-d', strtotime('+10 days')), $branch]);
    ok(count($ms) === 3, 'found 3 eligible members', $ms);
    $r = [];
    foreach ($ms as $m) { $x = $owner->post("/api/classes/$cid/bookings", ['member_id' => $m['id'], 'date' => $date]); ok($x['status'] === 200, 'booking call', $x['raw']); $r[] = $x; }
    eq($r[0]['data']['status'] ?? null, 'booked', '1st booked'); eq($r[1]['data']['status'] ?? null, 'booked', '2nd booked');
    eq($r[2]['data']['status'] ?? null, 'waitlist', '3rd waitlisted');
    status($owner->post("/api/classes/$cid/bookings", ['member_id' => $ms[0]['id'], 'date' => $date]), 422, 'double booking');
    $owner->put('/api/bookings/' . $r[0]['data']['id'], ['status' => 'cancelled']);
    eq(q1('SELECT status FROM class_bookings WHERE id = ?', [$r[2]['data']['id']]), 'booked', 'waitlist promoted');
});

test('PT: booking needs a pack, clashes blocked, done uses a session', function () use ($owner, $karthik) {
    $tr = (int)q1('SELECT id FROM users WHERE email = ?', ['trainer@demo.gym']);
    $when = date('Y-m-d', strtotime('+2 days')) . ' 11:00:00';
    $p = $owner->post('/api/pt', ['member_id' => $karthik, 'trainer_id' => $tr, 'starts_at' => $when]);
    status($p, 200, 'booked');
    status($owner->post('/api/pt', ['member_id' => $karthik, 'trainer_id' => $tr, 'starts_at' => $when]), 422, 'trainer clash');
    $noPack = (int)q1("SELECT id FROM members WHERE phone = '9333333333'");
    status($owner->post('/api/pt', ['member_id' => $noPack, 'trainer_id' => $tr, 'starts_at' => date('Y-m-d', strtotime('+3 days')) . ' 09:00:00']), 422, 'no PT pack');
    $before = (int)q1("SELECT sessions_used FROM memberships WHERE member_id = ? AND kind = 'pt' AND status = 'active' ORDER BY end_date LIMIT 1", [$karthik]);
    $owner->put('/api/pt/' . $p['data']['id'], ['status' => 'done']);
    eq((int)q1("SELECT sessions_used FROM memberships WHERE member_id = ? AND kind = 'pt' AND status = 'active' ORDER BY end_date LIMIT 1", [$karthik]), $before + 1, 'session used');
});

test('store: sale takes stock out, cancel returns it with a credit note', function () use ($owner, $gymA) {
    $p = q("SELECT * FROM products WHERE gym_id = ? AND track_stock = 1 AND stock > 3 LIMIT 1", [$gymA])[0];
    $r = $owner->post('/api/store/sale', ['items' => [['product_id' => $p['id'], 'qty' => 2]], 'pay_mode' => 'cash']);
    eq((int)q1('SELECT stock FROM products WHERE id = ?', [$p['id']]), $p['stock'] - 2, 'stock -2');
    status($owner->post('/api/store/sale', ['items' => [['product_id' => $p['id'], 'qty' => 100]]]), 422, 'cannot oversell');
    $c = $owner->post("/api/invoices/{$r['data']['invoice_id']}/cancel", ['reason' => 'Returned']);
    ok(str_starts_with((string)($c['data']['credit_note'] ?? ''), 'CN/'), 'credit note', $c['raw']);
    eq((int)q1('SELECT stock FROM products WHERE id = ?', [$p['id']]), (int)$p['stock'], 'stock restored');
});

test('member app: renew with online payment (demo) and AutoPay flag', function () use ($plan) {
    $m = new Client('10.6.0.1');
    $o = $m->post('/api/m/auth/otp', ['gym' => 'demo', 'phone' => '9876500455']);
    db()->exec("UPDATE otp_codes SET attempts = 0 WHERE phone = '9876500455'");
    $o = $m->post('/api/m/auth/otp', ['gym' => 'demo', 'phone' => '9876500455']);
    $m->post('/api/m/auth/verify', ['gym' => 'demo', 'phone' => '9876500455', 'code' => $o['data']['demo_code']]);
    $r = $m->post('/api/m/renew', ['plan_id' => $plan('Quarterly')]);
    status($r, 200, 'renew order');
    ok(!empty($r['data']['order']['demo']), 'demo order when no Razorpay keys');
    $v = $m->post('/api/m/pay/verify', ['invoice_id' => $r['data']['invoice']['id'], 'demo' => true]);
    eq($v['data']['invoice']['status'] ?? null, 'paid', 'paid');
    status($m->post('/api/m/pay/verify', ['invoice_id' => $r['data']['invoice']['id'], 'razorpay_order_id' => 'x', 'razorpay_payment_id' => 'y', 'razorpay_signature' => 'z']), 422, 'bad Razorpay signature rejected');
    $b = $m->post('/api/m/classes/' . (int)q1('SELECT id FROM classes WHERE day_of_week = ? AND is_active = 1 LIMIT 1', [(int)date('w', strtotime('+7 days'))]) . '/book', ['date' => date('Y-m-d', strtotime('+7 days'))]);
    ok(in_array($b['status'], [200, 422], true), 'class booking from app responds', $b['raw']);
});

test('reports, GST maths and exports', function () use ($owner) {
    $m = date('Y-m');
    $g = $owner->get('/api/billing/gst', ['month' => $m]);
    $sum = q("SELECT ROUND(SUM(taxable),2) t, ROUND(SUM(cgst),2) c FROM invoices WHERE DATE_FORMAT(invoice_date,'%Y-%m') = ? AND status <> 'cancelled' AND gym_id = (SELECT id FROM gyms WHERE slug='demo')", [$m])[0];
    eq(round($g['data']['totals']['taxable'], 2), (float)$sum['t'], 'taxable matches invoices');
    eq(round($g['data']['totals']['cgst'], 2), (float)$sum['c'], 'CGST matches');
    $x = $owner->get('/api/billing/export/tally', ['month' => $m])['raw'];
    ok(@simplexml_load_string($x) !== false, 'Tally XML is valid XML');
    foreach (['collections', 'invoices', 'dues', 'expenses', 'attendance', 'staff_attendance', 'expiring', 'leads'] as $k) status($owner->get("/api/reports/export/$k"), 200, "export $k");
    status($owner->get('/api/reports'), 200, 'summary');
    status($owner->get('/api/reports/branches'), 200, 'branches');
    status($owner->get('/api/backup'), 200, 'backup');
    ok(!str_contains($owner->get('/api/backup')['raw'], 'password_hash'), 'backup has no password hashes');
});

test('payroll, campaigns, churn and cron run cleanly', function () use ($owner) {
    $p = $owner->post('/api/payroll/run', ['period' => date('Y-m')]);
    ok(count($p['data']['items'] ?? []) >= 5, 'payroll lines', $p['raw']);
    $c = $owner->post('/api/campaigns', ['name' => 'T', 'channel' => 'whatsapp', 'body' => 'Hi {name}', 'segment' => ['status' => 'active']]);
    ok(($c['data']['recipients'] ?? 0) > 10, 'campaign recipients', $c['raw']);
    $sample = q1("SELECT body FROM messages WHERE campaign_id = ? LIMIT 1", [$c['data']['id']]);
    ok(!str_contains((string)$sample, '{name}'), 'placeholder rendered', $sample);
    status($owner->post('/api/insights/rescore'), 200, 'churn rescore');
    $out = shell_exec('GYM_DB=gymapp_test GYM_URL=' . BASE . ' php ' . escapeshellarg(dirname(__DIR__, 2) . '/api/cron.php') . ' --force 2>&1');
    ok(str_contains((string)$out, 'messages_sent') && !str_contains((string)$out, 'failed'), 'cron --force', $out);
    eq((int)q1("SELECT COUNT(*) FROM messages WHERE status = 'queued' AND send_after <= NOW()"), 0, 'queue drained');
});

test('import members from spreadsheet rows', function () use ($owner) {
    $r = $owner->post('/api/members/import', ['rows' => [
        ['name' => 'Imported One', 'phone' => '9444444441', 'plan' => 'Monthly', 'end_date' => date('Y-m-d', strtotime('+20 days'))],
        ['name' => 'Imported Two', 'phone' => '+91 94444 44442'],
        ['name' => 'Bad Phone', 'phone' => '12345'],
        ['name' => 'Imported One again', 'phone' => '9444444441'],
    ]]);
    eq($r['data']['created'] ?? null, 2, 'two created');
    eq(count($r['data']['skipped'] ?? []), 2, 'two skipped with reasons');
    eq(q1("SELECT status FROM memberships ms JOIN members m ON m.id = ms.member_id WHERE m.phone = '9444444441'"), 'active', 'imported plan active');
});

test('public website and trial booking (with honeypot)', function () use ($anon) {
    $g = $anon->get('/api/public/gym/demo');
    ok(count($g['data']['plans'] ?? []) > 3, 'plans listed');
    ok(!isset($g['data']['settings']), 'no settings leak');
    $t = $anon->post('/api/public/gym/demo/trial', ['name' => 'Web Lead', 'phone' => '9555555555', 'trial_date' => date('Y-m-d', strtotime('+1 day'))]);
    ok(strlen((string)($t['data']['trial_pass'] ?? '')) === 6, 'trial pass issued', $t['raw']);
    $before = (int)q1('SELECT COUNT(*) FROM leads');
    $anon->post('/api/public/gym/demo/trial', ['name' => 'Bot', 'phone' => '9555555556', 'website' => 'spam']);
    eq((int)q1('SELECT COUNT(*) FROM leads'), $before, 'honeypot drops bots');
});

test('platform console: only platform admins; suspension locks the gym out', function () use ($owner, $other) {
    status($owner->get('/api/platform/gyms'), 403, 'gym owner blocked');
    q("INSERT INTO users (gym_id, name, email, password_hash, role) VALUES (NULL, 'Platform', 'platform@test.gym', ?, 'platform_admin')", [password_hash('Platform@12345', PASSWORD_BCRYPT)]);
    $pa = (new Client('10.7.0.1'))->login('platform@test.gym', 'Platform@12345');
    $list = $pa->get('/api/platform/gyms');
    ok(count($list['data'] ?? []) === 2, 'sees both gyms');
    status($pa->get('/api/members'), 403, 'platform admin cannot browse a gym directly');
    $pa->put('/api/platform/gyms/' . $other['gym_id'], ['status' => 'suspended']);
    $c = new Client('10.7.0.2');
    status($c->post('/api/auth/login', ['email' => 'owner@other.gym', 'password' => 'Other@12345']), 403, 'suspended gym cannot log in');
    $pa->put('/api/platform/gyms/' . $other['gym_id'], ['status' => 'active']);
});

test('real (non-demo) gyms never get demo shortcuts', function () use ($otherOwner) {
    $otherOwner = (new Client('10.8.0.9'))->login('owner@other.gym', 'Other@12345');
    $r = $otherOwner->post('/api/members', ['name' => 'Real Member', 'phone' => '9666666666']);
    status($r, 200, 'member in real gym');
    $c = new Client('10.8.0.1');
    $o = $c->post('/api/m/auth/otp', ['gym' => 'other-gym', 'phone' => '9666666666']);
    status($o, 200, 'otp request');
    ok(!isset($o['data']['demo_code']), 'no on-screen code for a real gym', $o['raw']);
    eq($o['data']['via'] ?? null, 'none', 'reports that no channel is connected');
    $inv = $otherOwner->post('/api/invoices', ['member_id' => $r['data']['id'], 'items' => [['description' => 'Test', 'rate' => 100]]]);
    status((new Client('10.8.0.2'))->get('/api/public/demo-pay/' . $inv['data']['id']), 404, 'demo pay page off for real gym');
    $msg = $otherOwner->post('/api/members/' . $r['data']['id'] . '/message', ['body' => 'hello']);
    eq($msg['data']['status'] ?? null, 'failed', 'WhatsApp not simulated for a real gym');
    $me = $otherOwner->get('/api/auth/me');
    eq($me['data']['demo_mode'], false, 'demo flag off');
});

// ---------- report ----------
printf("\n%d passed, %d failed in %.1fs\n", $pass, $fail, microtime(true) - $t0);
foreach ($failures as $f) echo "  ✗ $f\n";
exit($fail ? 1 : 0);
