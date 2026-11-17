<?php

namespace App\Helpers;

use PDO;
use Exception;

require_once dirname(__DIR__, 2) . '/logs/databaselog.php';

class DB {
    private static ?\PDO $connection = null;
    private static array $connections = [];

    public static function getConnection(?string $dbName = null): \PDO {
        $key = $dbName ?? 'default';
        if (isset(self::$connections[$key])) {
            return self::$connections[$key];
        }

        $configPath = dirname(__DIR__, 2) . '/config/database.php';
        if (!file_exists($configPath)) {
            throw new Exception("Database configuration file not found at: {$configPath}");
        }
        $config = require $configPath;

        $targetDatabase = $dbName ?? ($config['database'] ?? 'serveasy_v2');

        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=%s",
            $config['host'],
            $config['port'],
            $targetDatabase,
            $config['charset']
        );

        try {
            $pdoClass = class_exists('\LoggedPDO') ? '\LoggedPDO' : '\PDO';
            $conn = new $pdoClass(
                $dsn,
                $config['username'],
                $config['password'],
                $config['options']
            );
            self::$connections[$key] = $conn;
            if ($dbName === null) {
                self::$connection = $conn;
            }
            return $conn;
        } catch (Exception $e) {
            throw new Exception("Database Connection Error: " . $e->getMessage());
        }
    }

    public static function beginTransaction(): bool {
        return self::getConnection()->beginTransaction();
    }

    public static function commit(): bool {
        return self::getConnection()->commit();
    }

    public static function rollBack(): bool {
        return self::getConnection()->rollBack();
    }
}
