<?php

class DatabaseLogger {
    private static $logFile = __DIR__ . '/database.log';

    public static function log($sql, $params, $duration, $rowCount) {
        $timestamp = date('Y-m-d H:i:s');
        $deviceInfo = self::getDeviceInfo();
        $caller = self::getCaller();

        $logData = [
            'timestamp' => $timestamp,
            'sql' => $sql,
            'params' => $params,
            'duration' => round($duration * 1000, 2), // store duration in milliseconds
            'row_count' => $rowCount,
            'caller' => $caller,
            'device' => $deviceInfo
        ];

        $logLine = json_encode($logData) . PHP_EOL;
        @file_put_contents(self::$logFile, $logLine, FILE_APPEND);
    }

    private static function getDeviceInfo() {
        return [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown'
        ];
    }

    private static function getCaller() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($trace as $step) {
            if (isset($step['file'])) {
                $file = $step['file'];
                if (strpos($file, 'db_connection.php') === false && strpos($file, 'databaselog.php') === false) {
                    $cleanFile = str_replace('\\', '/', $file);
                    if (($pos = strpos($cleanFile, 'ServEasy/')) !== false) {
                        $cleanFile = substr($cleanFile, $pos);
                    }
                    return $cleanFile . ':' . ($step['line'] ?? 0);
                }
            }
        }
        return 'Unknown';
    }
}

class LoggedPDOStatement extends PDOStatement {
    private $pdo;
    private $params = [];

    protected function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function bindValue($param, $value, $type = PDO::PARAM_STR): bool {
        $this->params[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

    public function bindParam($param, &$var, $type = PDO::PARAM_STR, $maxLength = 0, $driverOptions = null): bool {
        $this->params[$param] = &$var;
        return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    public function execute($params = null): bool {
        $startTime = microtime(true);
        $result = parent::execute($params);
        $duration = microtime(true) - $startTime;
        
        $loggedParams = $params !== null ? $params : $this->params;
        
        DatabaseLogger::log($this->queryString, $loggedParams, $duration, $result ? $this->rowCount() : 0);
        return $result;
    }
}

class LoggedPDO extends PDO {
    public static function rewriteSql(string $sql): string {
        $prefix = $_ENV['DB_PREFIX'] ?? '';
        if (empty($prefix)) {
            $mainDb = $_ENV['DB_DATABASE'] ?? ($_ENV['DB_NAME'] ?? '');
            if (preg_match('/^([a-zA-Z0-9]+_)serveasy$/', $mainDb, $m)) {
                $prefix = $m[1];
            }
        }
        if (!empty($prefix)) {
            $singleDb = $_ENV['DB_SINGLE_DATABASE'] ?? 'true';
            if ($singleDb === 'true' || $singleDb === true || $singleDb === '1') {
                $targetDb = '`' . $prefix . 'serveasy`';
                $replaced = preg_replace('/`' . preg_quote($prefix, '/') . '(se_booking|se_services|se_admin|serveasy)`/i', $targetDb, $sql);
                if ($replaced !== null) $sql = $replaced;
                $replaced2 = preg_replace('/`(se_booking|se_services|se_admin|serveasy)`/i', $targetDb, $sql);
                if ($replaced2 !== null) $sql = $replaced2;
            } else {
                // Replace unprefixed database names with prefixed names if not already prefixed
                $sql = preg_replace('/(?<!`' . preg_quote($prefix, '/') . ')`se_booking`/', '`' . $prefix . 'se_booking`', $sql);
                $sql = preg_replace('/(?<!`' . preg_quote($prefix, '/') . ')`se_services`/', '`' . $prefix . 'se_services`', $sql);
                $sql = preg_replace('/(?<!`' . preg_quote($prefix, '/') . ')`se_admin`/', '`' . $prefix . 'se_admin`', $sql);
                $sql = preg_replace('/(?<!`' . preg_quote($prefix, '/') . ')`serveasy`/', '`' . $prefix . 'serveasy`', $sql);
            }
        }
        return (string)$sql;
    }

    public function __construct($dsn, $username = null, $password = null, $options = null) {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggedPDOStatement::class, [$this]]);
    }

    public function prepare($statement, $options = []): PDOStatement|false {
        $rewritten = self::rewriteSql($statement);
        return parent::prepare($rewritten, $options);
    }

    public function exec($statement): int|false {
        $rewritten = self::rewriteSql($statement);
        $startTime = microtime(true);
        $result = parent::exec($rewritten);
        $duration = microtime(true) - $startTime;
        DatabaseLogger::log($rewritten, [], $duration, $result);
        return $result;
    }

    public function query($statement, ...$args): PDOStatement|false {
        $rewritten = self::rewriteSql($statement);
        $startTime = microtime(true);
        $result = parent::query($rewritten, ...$args);
        $duration = microtime(true) - $startTime;
        $rowCount = $result ? $result->rowCount() : null;
        DatabaseLogger::log($rewritten, [], $duration, $rowCount);
        return $result;
    }
}

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    $logFile = __DIR__ . '/database.log';
    $logs = [];
    $totalDuration = 0;
    $slowQueries = 0;
    
    if (file_exists($logFile)) {
        $allLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = is_array($allLines) ? array_slice($allLines, -200) : [];
        foreach ($lines as $line) {
            $parsed = json_decode($line, true);
            if ($parsed) {
                $logs[] = $parsed;
                $totalDuration += $parsed['duration'] ?? 0;
                if (($parsed['duration'] ?? 0) > 20) {
                    $slowQueries++;
                }
            }
        }
        $logs = array_reverse($logs); // Show newest first
    }
    
    $totalLogs = count($logs);
    $avgDuration = $totalLogs > 0 ? round($totalDuration / $totalLogs, 2) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Request Log Monitor</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --text-color: #f1f5f9;
            --card-bg: #111827;
            --border-color: #1f2937;
            --primary: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 2rem;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        h1 {
            font-weight: 600;
            margin: 0;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1.25rem;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
        .stat-card .label {
            font-size: 0.875rem;
            color: #94a3b8;
            margin-bottom: 0.5rem;
        }
        .stat-card .value {
            font-size: 1.75rem;
            font-weight: 700;
        }
        .search-box {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
        }
        .search-box input {
            background: #000;
            border: 1px solid var(--border-color);
            color: var(--text-color);
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            width: 100%;
            max-width: 400px;
        }
        .logs-container {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            overflow-x: auto;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        th, td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.875rem;
        }
        th {
            font-weight: 600;
            color: #94a3b8;
            background-color: rgba(0, 0, 0, 0.2);
            white-space: nowrap;
        }
        tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }
        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            letter-spacing: 0.025em;
        }
        .badge-fast { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .badge-warn { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .badge-slow { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        
        pre.sql-code {
            margin: 0;
            background: #000;
            padding: 0.75rem;
            border-radius: 0.375rem;
            font-family: 'Fira Code', 'Courier New', Courier, monospace;
            font-size: 0.85rem;
            color: #a7f3d0;
            white-space: pre-wrap;
            word-break: break-all;
            border: 1px solid #111827;
        }
        .device-info {
            font-size: 0.75rem;
            color: #94a3b8;
            max-width: 250px;
        }
        .caller-info {
            font-family: monospace;
            font-size: 0.8rem;
            color: #60a5fa;
            max-width: 200px;
            word-break: break-all;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>🗄️ Database Request Monitor</h1>
        <div>Logs Count: <strong><?php echo $totalLogs; ?></strong></div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Queries Logged</div>
            <div class="value"><?php echo $totalLogs; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Average Response Time</div>
            <div class="value" style="color: var(--success);"><?php echo $avgDuration; ?> ms</div>
        </div>
        <div class="stat-card">
            <div class="label">Slow Queries (> 20 ms)</div>
            <div class="value" style="color: <?php echo $slowQueries > 0 ? 'var(--danger)' : 'var(--success)'; ?>;"><?php echo $slowQueries; ?></div>
        </div>
    </div>

    <div class="search-box">
        <input type="text" id="searchInput" placeholder="Search SQL query, parameters, or caller..." oninput="filterLogs()">
    </div>

    <div class="logs-container">
        <table>
            <thead>
                <tr>
                    <th style="width: 140px;">Timestamp</th>
                    <th>SQL Query & Params</th>
                    <th style="width: 100px;">Duration</th>
                    <th style="width: 80px;">Rows</th>
                    <th style="width: 180px;">Caller Location</th>
                    <th>Client Request</th>
                </tr>
            </thead>
            <tbody id="logsTableBody">
                <?php if (empty($logs)): ?>
                <tr class="no-logs-row">
                    <td colspan="6" style="text-align: center; color: #94a3b8;">No database request logs found.</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr class="log-row">
                        <td style="white-space: nowrap;"><?php echo htmlspecialchars($log['timestamp'] ?? '-'); ?></td>
                        <td class="sql-td">
                            <pre class="sql-code"><?php echo htmlspecialchars($log['sql'] ?? '-'); ?></pre>
                            <?php if (!empty($log['params'])): ?>
                                <div style="margin-top: 6px; font-size: 0.75rem; color: #94a3b8;">
                                    <strong>Parameters:</strong> <code><?php echo htmlspecialchars(json_encode($log['params'])); ?></code>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                                $dur = $log['duration'] ?? 0;
                                if ($dur < 5) {
                                    $badge = 'badge-fast';
                                } elseif ($dur < 20) {
                                    $badge = 'badge-warn';
                                } else {
                                    $badge = 'badge-slow';
                                }
                            ?>
                            <span class="badge <?php echo $badge; ?>"><?php echo $dur; ?> ms</span>
                        </td>
                        <td>
                            <span class="badge" style="background: rgba(255,255,255,0.05); color: #f1f5f9;"><?php echo htmlspecialchars($log['row_count'] ?? '0'); ?></span>
                        </td>
                        <td class="caller-td">
                            <span class="caller-info"><?php echo htmlspecialchars($log['caller'] ?? '-'); ?></span>
                        </td>
                        <td class="device-info">
                            <div><strong>IP:</strong> <?php echo htmlspecialchars($log['device']['ip'] ?? '-'); ?></div>
                            <div><strong>Method:</strong> <?php echo htmlspecialchars($log['device']['request_method'] ?? '-'); ?></div>
                            <div><strong>URI:</strong> <?php echo htmlspecialchars($log['device']['request_uri'] ?? '-'); ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        function filterLogs() {
            const query = document.getElementById('searchInput').value.toLowerCase();
            const rows = document.querySelectorAll('.log-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const sqlText = row.querySelector('.sql-td').innerText.toLowerCase();
                const callerText = row.querySelector('.caller-td').innerText.toLowerCase();
                
                if (sqlText.includes(query) || callerText.includes(query)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Update total count
            document.querySelector('.header strong').innerText = visibleCount;
        }
    </script>
</body>
</html>
<?php
}
