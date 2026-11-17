<?php

// Ensure .env is loaded if present
if (!isset($_ENV['DB_HOST']) && file_exists(__DIR__ . '/../.env')) {
    $envLines = @file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($envLines) {
        foreach ($envLines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                list($k, $v) = explode('=', $line, 2);
                $k = trim($k);
                $v = trim(trim($v), "\"'");
                $_ENV[$k] = $v;
                putenv("{$k}={$v}");
            }
        }
    }
}

return [
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'name' => $_ENV['DB_DATABASE'] ?? ($_ENV['DB_NAME'] ?? 'serveasy_v2'),
        'user' => $_ENV['DB_USERNAME'] ?? ($_ENV['DB_USER'] ?? 'root'),
        'pass' => $_ENV['DB_PASSWORD'] ?? ($_ENV['DB_PASS'] ?? ''),
        'charset' => 'utf8mb4'
    ],

    'app' => [
        'base_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
            . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ServEasy-v2/backend/"
    ],

    'env' => $_ENV['APP_ENV'] ?? 'development'
];


