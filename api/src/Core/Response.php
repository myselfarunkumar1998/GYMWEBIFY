<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static bool $testMode = false;
    public static array $cookies = [];

    public function __construct(public int $status = 200, public mixed $body = null, public array $headers = [], public bool $raw = false) {}

    public static function json(mixed $data, int $status = 200): self { return new self($status, ['data' => $data]); }
    public static function ok(): self { return new self(200, ['data' => ['ok' => true]]); }

    public static function file(string $content, string $mime, string $filename): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new self(200, $content, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="' . $safe . '"'], true);
    }

    public static function text(string $content, int $status = 200, string $mime = 'text/plain'): self
    {
        return new self($status, $content, ['Content-Type' => $mime . '; charset=utf-8'], true);
    }

    public static function error(HttpException $e): self
    {
        $err = ['code' => $e->errCode ?: 'error', 'message' => $e->getMessage()];
        if ($e->fields) $err['fields'] = $e->fields;
        return new self($e->status, ['error' => $err]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Referrer-Policy: same-origin');
        foreach ($this->headers as $k => $v) header("$k: $v");
        if ($this->raw) { echo $this->body; return; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function setCookie(string $name, string $value, int $expires): void
    {
        $opts = [
            'expires' => $expires,
            'path' => '/',
            'secure' => (bool)Config::get('cookie_secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if (self::$testMode) { self::$cookies[$name] = $value; return; }
        setcookie($name, $value, $opts);
    }
}
