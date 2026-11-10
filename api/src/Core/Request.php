<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public array $params = [];
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private readonly string $rawBody,
        public readonly array $headers,
        public readonly array $files = [],
        public readonly array $post = [],
    ) {}

    public static function fromGlobals(string $basePath): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($basePath !== '' && str_starts_with($uri, $basePath)) $uri = substr($uri, strlen($basePath));
        $uri = '/' . trim($uri, '/');
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
        if (isset($_SERVER['CONTENT_TYPE'])) $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        $body = file_get_contents('php://input', false, null, 0, 2_000_000) ?: '';
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $uri, $_GET, $body, $headers, $_FILES, $_POST);
    }

    public function header(string $name): ?string { return $this->headers[strtolower($name)] ?? null; }
    public function raw(): string { return $this->rawBody; }

    public function json(): array
    {
        if ($this->json === null) {
            $this->json = [];
            if ($this->rawBody !== '' && str_contains((string)$this->header('content-type'), 'json')) {
                $d = json_decode($this->rawBody, true);
                if (!is_array($d)) throw HttpException::bad('Invalid JSON body');
                $this->json = $d;
            } elseif ($this->post) {
                $this->json = $this->post;
            }
        }
        return $this->json;
    }

    public function input(string $key, mixed $default = null): mixed { return $this->json()[$key] ?? $default; }
    public function q(string $key, mixed $default = null): mixed { return $this->query[$key] ?? $default; }
    public function id(string $name = 'id'): int { return (int)($this->params[$name] ?? 0); }

    public function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
    public function userAgent(): string { return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250); }

    public function page(): array
    {
        $limit = max(1, min(200, (int)$this->q('limit', 50)));
        $page = max(1, (int)$this->q('page', 1));
        return [$limit, ($page - 1) * $limit];
    }
}
