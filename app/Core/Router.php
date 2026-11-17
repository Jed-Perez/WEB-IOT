<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function dispatch(string $method, string $path): void
    {
        $path = rtrim($path, '/') ?: '/';
        $method = strtoupper($method);
        $handler = $this->routes[($method === 'HEAD' ? 'GET' : $method) . ' ' . $path] ?? null;

        if ($handler === null) {
            $allowed = [];
            foreach (['GET', 'POST'] as $candidate) {
                if (isset($this->routes[$candidate . ' ' . $path])) $allowed[] = $candidate;
            }
            if ($allowed) {
                header('Allow: ' . implode(', ', $allowed));
                throw new HttpException(405, 'Method not allowed.');
            }
            throw new HttpException(404, 'Page not found.');
        }

        [$controllerClass, $action] = $handler;
        if ($method === 'HEAD') ob_start();
        try { (new $controllerClass())->$action(); }
        finally { if ($method === 'HEAD') ob_end_clean(); }
    }

    private function add(string $method, string $path, array $handler): void
    {
        $path = rtrim($path, '/') ?: '/';
        $this->routes[$method . ' ' . $path] = $handler;
    }
}
