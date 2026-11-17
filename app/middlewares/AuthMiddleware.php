<?php

namespace App\Middlewares;

use App\Helpers\JWT;
use App\Helpers\Auth;
use App\Helpers\Response;
use Exception;

class AuthMiddleware {
    private ?string $requiredRole;

    public function __construct(?string $requiredRole = null) {
        $this->requiredRole = $requiredRole;
    }

    public function handle(): void {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);

        $token = null;
        if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $token = $matches[1];
        } elseif (!empty($_COOKIE['access_token'])) {
            $token = $_COOKIE['access_token'];
        }

        if (!$token) {
            Response::error("Authentication token required", 401);
        }

        $config = require dirname(__DIR__, 2) . '/config/app.php';

        try {
            $payload = JWT::decode($token, $config['jwt']['secret']);
            Auth::setUser($payload);

            if ($this->requiredRole && !Auth::hasRole($this->requiredRole)) {
                Response::error("Access denied: insufficient permissions", 403);
            }
        } catch (Exception $e) {
            Response::error("Invalid or expired authentication token: " . $e->getMessage(), 401);
        }
    }
}
