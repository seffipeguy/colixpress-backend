<?php

namespace App\Core;

class Router
{
    private array $routes = [];
    private string $prefix = '';
    private array $middleware = [];

    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->middleware;

        $this->prefix = $previousPrefix . $prefix;
        $this->middleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->prefix = $previousPrefix;
        $this->middleware = $previousMiddleware;
    }

    public function get(string $path, string $controller, string $method): void
    {
        $this->addRoute('GET', $path, $controller, $method);
    }

    public function post(string $path, string $controller, string $method): void
    {
        $this->addRoute('POST', $path, $controller, $method);
    }

    public function put(string $path, string $controller, string $method): void
    {
        $this->addRoute('PUT', $path, $controller, $method);
    }

    public function delete(string $path, string $controller, string $method): void
    {
        $this->addRoute('DELETE', $path, $controller, $method);
    }

    private function addRoute(string $httpMethod, string $path, string $controller, string $method): void
    {
        $fullPath = $this->prefix . $path;
        $this->routes[] = [
            'method'     => $httpMethod,
            'path'       => $fullPath,
            'controller' => $controller,
            'action'     => $method,
            'middleware'  => $this->middleware,
        ];
    }

    public function dispatch(): void
    {
        $request = new Request();
        $method = $request->method();
        $uri = $request->uri();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchRoute($route['path'], $uri);
            if ($params !== false) {
                $request->setParams($params);

                // Run middleware
                foreach ($route['middleware'] as $middlewareClass) {
                    $mw = new $middlewareClass();
                    $mw->handle($request);
                }

                // Call controller
                $controllerClass = $route['controller'];
                $action = $route['action'];

                if (!class_exists($controllerClass)) {
                    Response::error("Controller {$controllerClass} not found", 500);
                }

                $controller = new $controllerClass();
                if (!method_exists($controller, $action)) {
                    Response::error("Action {$action} not found", 500);
                }

                $controller->$action($request);
                return;
            }
        }

        Response::notFound('Endpoint not found');
    }

    private function matchRoute(string $routePath, string $uri): array|false
    {
        // Convert route pattern to regex: /orders/{id} => /orders/([^/]+)
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '([^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $uri, $matches)) {
            array_shift($matches);

            // Extract param names
            preg_match_all('/\{([a-zA-Z_]+)\}/', $routePath, $paramNames);
            $params = [];
            foreach ($paramNames[1] as $i => $name) {
                $params[$name] = $matches[$i] ?? null;
            }
            return $params;
        }

        return false;
    }
}
