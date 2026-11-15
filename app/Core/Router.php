<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Route options:
 *   auth       (bool, default true)  – requires a logged-in user
 *   school     (bool, default true)  – requires an active school in the session
 *   permission (string|null)         – "module.action" enforced server-side before the controller runs
 *   password   (bool, default false) – reachable while a password change is forced
 */
final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler, array $options = []): void
    {
        $this->add('GET', $path, $handler, $options);
    }

    public function post(string $path, array $handler, array $options = []): void
    {
        $this->add('POST', $path, $handler, $options);
    }

    private function add(string $method, string $path, array $handler, array $options): void
    {
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>\d+)', $path);
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'options' => $options + ['auth' => true, 'school' => true, 'permission' => null, 'password' => false],
        ];
    }

    /** @return array{0: array, 1: array<string,int>}|null */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['regex'], $path, $m)) {
                $params = [];
                foreach ($m as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = (int) $value;
                    }
                }
                return [$route, $params];
            }
        }
        return null;
    }

    public function pathExists(string $path): bool
    {
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path)) {
                return true;
            }
        }
        return false;
    }

    public function routes(): array
    {
        return $this->routes;
    }
}
