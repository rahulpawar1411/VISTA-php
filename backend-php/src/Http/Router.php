<?php
declare(strict_types=1);

namespace App\Http;

use App\Middleware\AuthMiddleware;

final class Router
{
    /** @var list<array{method: string, pattern: string, handler: callable, auth: bool, roles: ?list<string>}> */
    private array $routes = [];

    /** @param callable $handler @param list<string>|null $roles null=public, []=any auth, list=roles */
    public function add(string $method, string $pattern, callable $handler, ?array $roles = null): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'auth' => $roles !== null,
            'roles' => $roles,
        ];
    }

    public function get(string $pattern, callable $handler, ?array $roles = null): void
    {
        $this->add('GET', $pattern, $handler, $roles);
    }

    public function post(string $pattern, callable $handler, ?array $roles = null): void
    {
        $this->add('POST', $pattern, $handler, $roles);
    }

    public function put(string $pattern, callable $handler, ?array $roles = null): void
    {
        $this->add('PUT', $pattern, $handler, $roles);
    }

    public function patch(string $pattern, callable $handler, ?array $roles = null): void
    {
        $this->add('PATCH', $pattern, $handler, $roles);
    }

    public function delete(string $pattern, callable $handler, ?array $roles = null): void
    {
        $this->add('DELETE', $pattern, $handler, $roles);
    }

    public function dispatch(Request $req): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $req->method) {
                continue;
            }
            $params = $this->match($route['pattern'], $req->path);
            if ($params === null) {
                continue;
            }

            if ($route['auth']) {
                if (!AuthMiddleware::verify($req)) {
                    return;
                }
                if (is_array($route['roles']) && count($route['roles']) > 0) {
                    if (!AuthMiddleware::requireRole($req, $route['roles'])) {
                        return;
                    }
                }
            }

            ($route['handler'])($req, $params);
            return;
        }

        if (str_starts_with($req->path, '/api')) {
            Response::error('API route not found.', 404);
            return;
        }
        Response::error('Not found.', 404);
    }

    /** @return array<string, string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $pattern = '/' . trim($pattern, '/');
        if ($pattern !== '/') {
            $pattern = rtrim($pattern, '/') ?: '/';
        }
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (!preg_match($regex, $path, $m)) {
            return null;
        }
        $params = [];
        foreach ($m as $k => $v) {
            if (!is_int($k)) {
                $params[$k] = $v;
            }
        }
        return $params;
    }
}
