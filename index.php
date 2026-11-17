<?php
/**
 * ServEasy Central Backend API Engine
 * Healthcheck & Status Endpoint
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$bootstrap = __DIR__ . '/api/bootstrap.php';
$dbStatus = 'unknown';

if (file_exists($bootstrap)) {
    require_once $bootstrap;
    $dbHost = $_ENV['DB_HOST'] ?? 'localhost';
    $dbName = $_ENV['DB_DATABASE'] ?? '';
    $dbUser = $_ENV['DB_USERNAME'] ?? '';
    $dbPass = $_ENV['DB_PASSWORD'] ?? '';
    $dbPort = $_ENV['DB_PORT'] ?? 3306;

    if (!empty($dbName)) {
        try {
            $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2
            ]);
            $stmt = $pdo->query("SELECT 1");
            $dbStatus = 'connected (' . $dbName . ')';
        } catch (\Throwable $e) {
            $dbStatus = 'error: ' . $e->getMessage();
        }
    } else {
        $dbStatus = 'unconfigured (.env DB_DATABASE is empty)';
    }
}

echo json_encode([
    'status' => 'online',
    'service' => 'ServEasy Central API Engine',
    'version' => '2.0.0',
    'database' => $dbStatus,
    'timestamp' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT);
exit;
