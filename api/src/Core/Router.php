<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{string,string,callable,array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[0-9]+)', preg_replace('#\{([a-z_]+):s\}#', '(?P<$1>[A-Za-z0-9_-]+)', $pattern)) . '$#';
        $this->routes[] = [$method, $regex, $handler, $opts];
    }

    public function get(string $p, callable|array $h, array $o = []): void { $this->add('GET', $p, $h, $o); }
    public function post(string $p, callable|array $h, array $o = []): void { $this->add('POST', $p, $h, $o); }
    public function put(string $p, callable|array $h, array $o = []): void { $this->add('PUT', $p, $h, $o); }
    public function delete(string $p, callable|array $h, array $o = []): void { $this->add('DELETE', $p, $h, $o); }

    public function dispatch(Request $req): Response
    {
        $allowed = false;
        foreach ($this->routes as [$method, $regex, $handler, $opts]) {
            if (!preg_match($regex, $req->path, $m)) continue;
            if ($method !== $req->method) { $allowed = true; continue; }
            $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            Middleware::run($req, $opts);
            if (is_array($handler) && is_string($handler[0])) $handler = [new $handler[0](), $handler[1]];
            $res = $handler($req);
            return $res instanceof Response ? $res : Response::json($res);
        }
        throw $allowed ? new HttpException(405, 'Method not allowed') : HttpException::notFound('Endpoint not found');
    }
}
