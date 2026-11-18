<?php

namespace App\Helpers;

class Auth {
    private static ?array $user = null;

    public static function setUser(?array $user): void {
        self::$user = $user;
    }

    public static function reset(): void {
        self::$user = null;
    }

    public static function user(): ?array {
        if (self::$user === null) {
            self::initFromSession();
        }
        return self::$user;
    }

    public static function id(): ?int {
        $user = self::user();
        return $user['user_id'] ?? $user['id'] ?? null;
    }

    public static function email(): ?string {
        $user = self::user();
        return $user['email'] ?? null;
    }

    public static function role(): ?string {
        $user = self::user();
        return $user['role'] ?? null;
    }

    public static function check(): bool {
        return self::user() !== null;
    }

    public static function hasRole(string $role): bool {
        $userRole = strtoupper(self::role() ?? '');
        $targetRole = strtoupper($role);
        if ($targetRole === 'CUSTOMER') {
            return in_array($userRole, ['CUSTOMER', 'USER', 'CLIENT', 'SITE_USER']);
        }
        if ($targetRole === 'PROVIDER') {
            return in_array($userRole, ['PROVIDER', 'DEALER', 'SERVICE_PROVIDER']);
        }
        if ($targetRole === 'ADMIN') {
            return in_array($userRole, ['ADMIN', 'SUPER_ADMIN', 'SUPERADMIN', 'MASTER_ADMIN']);
        }
        return $userRole === $targetRole;
    }

    private static function initFromSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Try Bearer token first
        $authHeader = null;
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }
        if (!$authHeader && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!$authHeader && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $token = null;
        if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $token = $matches[1];
        } elseif (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            $token = trim($_SERVER['HTTP_X_AUTH_TOKEN']);
        } elseif (!empty($_COOKIE['access_token'])) {
            $token = $_COOKIE['access_token'];
        }

        if ($token) {
            $configPath = dirname(__DIR__, 2) . '/config/app.php';
            if (file_exists($configPath)) {
                $config = require $configPath;
                try {
                    $payload = JWT::decode($token, $config['jwt']['secret']);
                    self::$user = $payload;
                    return;
                } catch (\Exception $e) {
                    // Fail silently and check session
                }
            }
        }

        // Support explicit mobile app headers before session fallback
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $provId = $headers['X-Provider-Id'] ?? $headers['x-provider-id'] ?? ($_SERVER['HTTP_X_PROVIDER_ID'] ?? null);
        $custId = $headers['X-Customer-Id'] ?? $headers['x-customer-id'] ?? ($_SERVER['HTTP_X_CUSTOMER_ID'] ?? null);

        if ($provId) {
            self::$user = [
                'user_id' => (int)$provId,
                'role' => 'PROVIDER'
            ];
            return;
        } elseif ($custId) {
            self::$user = [
                'user_id' => (int)$custId,
                'role' => 'CUSTOMER'
            ];
            return;
        }

        if (isset($_SESSION['user_id'])) {
            self::$user = [
                'user_id' => (int)$_SESSION['user_id'],
                'role' => 'CUSTOMER'
            ];
        } elseif (isset($_SESSION['dealer'])) {
            self::$user = [
                'user_id' => (int)$_SESSION['dealer']['id'],
                'role' => 'PROVIDER',
                'email' => $_SESSION['dealer']['email'] ?? null
            ];
        } elseif (isset($_SESSION['delivery'])) {
            self::$user = [
                'user_id' => (int)$_SESSION['delivery']['id'],
                'role' => 'DELIVERY',
                'email' => $_SESSION['delivery']['email'] ?? null
            ];
        } elseif (isset($_SESSION['standard_admin'])) {
            self::$user = [
                'user_id' => (int)$_SESSION['standard_admin']['id'],
                'role' => 'ADMIN',
                'email' => $_SESSION['standard_admin']['email'] ?? null
            ];
        }

        // Support mobile app and external API clients passing customer_id, provider_id, or user_id in request body
        if (self::$user === null) {
            $reqProvId = $_REQUEST['provider_id'] ?? null;
            $reqCustId = $_REQUEST['customer_id'] ?? $_REQUEST['user_id'] ?? null;
            if (!$reqProvId && !$reqCustId) {
                $rawInput = @file_get_contents('php://input');
                if (!empty($rawInput)) {
                    $decoded = @json_decode($rawInput, true);
                    if (!empty($decoded['customer_id'])) {
                        $reqCustId = (int)$decoded['customer_id'];
                    }
                    if (!empty($decoded['user_id'])) {
                        $reqCustId = (int)$decoded['user_id'];
                    }
                    if (!empty($decoded['provider_id'])) {
                        $reqProvId = (int)$decoded['provider_id'];
                    }
                }
            }
            // If customer_id is present (even if provider_id is also in payload, e.g. booking creation),
            // the acting user is the customer!
            if ($reqCustId) {
                self::$user = [
                    'user_id' => (int)$reqCustId,
                    'role' => 'CUSTOMER'
                ];
            } elseif ($reqProvId) {
                // If this is a service booking payload (has service_id or schedule_date),
                // provider_id is the target provider being booked, NOT the requester!
                $hasServiceParam = !empty($_REQUEST['service_id']) || !empty($_REQUEST['schedule_date']);
                if (!$hasServiceParam && !empty($decoded)) {
                    $hasServiceParam = !empty($decoded['service_id']) || !empty($decoded['schedule_date']);
                }

                if (!$hasServiceParam) {
                    self::$user = [
                        'user_id' => (int)$reqProvId,
                        'role' => 'PROVIDER'
                    ];
                }
            }
        }
    }
}
