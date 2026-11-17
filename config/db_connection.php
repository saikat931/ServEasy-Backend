<?php
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../logs/databaselog.php';

// Load config file
$config = require __DIR__ . '/config.php';

// Database class
class Database
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        global $config;
        $db = $config['db'];

        // DSN (Data Source Name)
        $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";

        try {
            $this->pdo = new LoggedPDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    // Singleton connect method
    public static function connect()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }
}

// Create PDO instance
$pdo = Database::connect();
