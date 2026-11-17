<?php

namespace App\Helpers;

class Router {
    private static array $routes = [];
    private static array $globalMiddleware = [];

    public static function addGlobalMiddleware(string $middleware): void {
        self::$globalMiddleware[] = $middleware;
    }

    public static function add(string $method, string $path, string $controllerAction, array $middleware = []): void {
        self::$routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'controller' => $controllerAction,
            'middleware' => $middleware
        ];
    }

    public static function get(string $path, string $controllerAction, array $middleware = []): void {
        self::add('GET', $path, $controllerAction, $middleware);
    }

    public static function post(string $path, string $controllerAction, array $middleware = []): void {
        self::add('POST', $path, $controllerAction, $middleware);
    }

    public static function put(string $path, string $controllerAction, array $middleware = []): void {
        self::add('PUT', $path, $controllerAction, $middleware);
    }

    public static function delete(string $path, string $controllerAction, array $middleware = []): void {
        self::add('DELETE', $path, $controllerAction, $middleware);
    }

    public static function dispatch(string $uri, string $method): void {
        $uri = parse_url($uri, PHP_URL_PATH);
        $method = strtoupper($method);

        // CORS preflight request handling
        if ($method === 'OPTIONS') {
            header("Access-Control-Allow-Origin: *");
            header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
            header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
            http_response_code(200);
            exit;
        }

        foreach (self::$routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            // Convert routes like /api/bookings/{id} to matching regex
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                // Filter out numerical matches to keep named parameter bindings
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Run global middleware
                foreach (self::$globalMiddleware as $mid) {
                    $instance = new $mid();
                    $instance->handle();
                }

                // Run route specific middleware
                foreach ($route['middleware'] as $mid) {
                    $instance = new $mid();
                    $instance->handle();
                }

                // Parse controller and method
                list($controllerClass, $action) = explode('@', $route['controller']);
                $controllerNamespace = "App\\Controllers\\" . $controllerClass;

                if (!class_exists($controllerNamespace)) {
                    Response::error("Controller {$controllerNamespace} not found", 500);
                }

                $controllerInstance = new $controllerNamespace();
                if (!method_exists($controllerInstance, $action)) {
                    Response::error("Action {$action} not found in {$controllerNamespace}", 500);
                }

                // Parse JSON body input
                $input = [];
                $json = file_get_contents('php://input');
                if (!empty($json)) {
                    $input = json_decode($json, true) ?? [];
                }
                
                // Merge query parameters and body arguments
                $request = array_merge($_GET, $input, $params);

                // Execute action
                $controllerInstance->$action($request);
                return;
            }
        }

        Response::error("Route {$method} {$uri} not found", 404);
    }
}
