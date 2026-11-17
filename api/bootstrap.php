<?php
date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Register PSR-4 Autoloader for App\ namespace
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $parts = explode('\\', $relativeClass);
    
    // Convert intermediate folder names to lowercase (e.g. Controllers -> controllers)
    $className = array_pop($parts);
    $folders = array_map('strtolower', $parts);
    
    $filePath = $baseDir;
    if (!empty($folders)) {
        $filePath .= implode('/', $folders) . '/';
    }
    $filePath .= $className . '.php';

    if (file_exists($filePath)) {
        require_once $filePath;
    }
});

// 2. Load Environment Variables (supports host.env or .env)
$envPath = file_exists(dirname(__DIR__) . '/host.env') ? dirname(__DIR__) . '/host.env' : dirname(__DIR__) . '/.env';
\App\Helpers\Env::load($envPath);

// 3. Set JSON response header and CORS headers for API requests
if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') || str_contains($_SERVER['REQUEST_URI'] ?? '', '/backend/api/')) {
    header('Content-Type: application/json');

    // CORS support for mobile apps, emulators and web clients (Multi-Probability Compliant)
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!empty($origin) && $origin !== '*') {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        header("Access-Control-Allow-Origin: {$scheme}://{$host}");
        header("Access-Control-Allow-Credentials: true");
    }
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Provider-Id, X-Customer-Id, Accept, Origin");

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

