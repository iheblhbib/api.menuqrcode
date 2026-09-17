<?php

namespace App\Core;

class Router
{
    private array $routes = [];

    public function get(string $pattern, array $handler, array $middlewares = []): void
    {
        $this->add('GET', $pattern, $handler, $middlewares);
    }

    public function post(string $pattern, array $handler, array $middlewares = []): void
    {
        $this->add('POST', $pattern, $handler, $middlewares);
    }

    public function put(string $pattern, array $handler, array $middlewares = []): void
    {
        $this->add('PUT', $pattern, $handler, $middlewares);
    }

    public function patch(string $pattern, array $handler, array $middlewares = []): void
    {
        $this->add('PATCH', $pattern, $handler, $middlewares);
    }

    public function delete(string $pattern, array $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middlewares);
    }

    private function add(string $method, string $pattern, array $handler, array $middlewares): void
    {
        $this->routes[] = compact('method', 'pattern', 'handler', 'middlewares');
    }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = $this->match($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }

            $request->params = $params;

            foreach ($route['middlewares'] as $middleware) {
                $middleware($request);
            }

            [$class, $methodName] = $route['handler'];
            $controller = new $class();
            $controller->$methodName($request);
            return;
        }

        throw new NotFoundException('Route not found: ' . $request->method . ' ' . $request->path);
    }

    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        return $params;
    }
}
