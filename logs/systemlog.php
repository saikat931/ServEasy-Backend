<?php

class SystemLogger {
    private static $logFile = __DIR__ . '/system.log';
    private static $startTime = null;

    /**
     * Start the execution timer.
     */
    public static function startTimer() {
        self::$startTime = microtime(true);
    }

    /**
     * End the execution timer and return the duration in seconds.
     */
    public static function endTimer() {
        if (self::$startTime === null) return 0;
        return round(microtime(true) - self::$startTime, 4);
    }

    /**
     * Get detailed information about the client's device and request
     */
    private static function getDeviceInfo() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        // Handle proxies
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ipAddress = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }

        return [
            'ip' => $ipAddress,
            'user_agent' => $userAgent,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown'
        ];
    }

    /**
     * Write formatted log entry
     */
    private static function writeLog($type, $action, $details) {
        $timestamp = date('Y-m-d H:i:s');
        $deviceInfo = self::getDeviceInfo();
        
        $logData = [
            'timestamp' => $timestamp,
            'type' => $type,
            'action' => $action,
            'device' => $deviceInfo,
            'details' => $details
        ];

        // Format as JSON and append to file
        $logLine = json_encode($logData) . PHP_EOL;
        file_put_contents(self::$logFile, $logLine, FILE_APPEND);
    }

    /**
     * Log a general website request with its execution speed
     */
    public static function logRequest($executionTimeSec = null) {
        if ($executionTimeSec === null && self::$startTime !== null) {
            $executionTimeSec = self::endTimer();
        }
        
        self::writeLog('REQUEST', 'page_load', [
            'execution_time_sec' => $executionTimeSec
        ]);
    }

    /**
     * Log authentication actions: login attempts (success/unauthorized) and logouts
     */
    public static function logAuth($action, $userIdentity, $status) {
        self::writeLog('AUTH', $action, [
            'identity' => $userIdentity,
            'status' => $status
        ]);
    }
}

/**
 * Visual Dashboard to render the logs if the file is accessed directly via browser.
 */
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    $logFile = __DIR__ . '/system.log';
    $logs = [];
    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parsed = json_decode($line, true);
            if ($parsed) {
                $logs[] = $parsed;
            }
        }
        $logs = array_reverse($logs); // Show newest first
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Monitor Dashboard</title>
    <style>
        :root {
            --bg-color: #0f172a;
            --text-color: #e2e8f0;
            --card-bg: #1e293b;
            --border-color: #334155;
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
        .badge-request { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .badge-auth { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .badge-success { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .badge-danger { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        
        pre.details {
            margin: 0;
            background: rgba(0, 0, 0, 0.3);
            padding: 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            color: #94a3b8;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .device-info {
            font-size: 0.75rem;
            color: #94a3b8;
            max-width: 300px;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>📊 System Monitor</h1>
        <div>Total Logs: <strong><?php echo count($logs); ?></strong></div>
    </div>

    <div class="logs-container">
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Type</th>
                    <th>Action</th>
                    <th>Device Info</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: #94a3b8;">No logs found yet.</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="white-space: nowrap;"><?php echo htmlspecialchars($log['timestamp'] ?? '-'); ?></td>
                        <td>
                            <?php 
                                $type = $log['type'] ?? 'UNKNOWN'; 
                                $badgeClass = $type === 'REQUEST' ? 'badge-request' : ($type === 'AUTH' ? 'badge-auth' : '');
                            ?>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($type); ?></span>
                        </td>
                        <td>
                            <?php 
                                $action = htmlspecialchars($log['action'] ?? '-'); 
                                if (isset($log['details']['status'])) {
                                    $status = $log['details']['status'];
                                    $sClass = $status === 'success' ? 'badge-success' : 'badge-danger';
                                    $action .= " <span class='badge {$sClass}'>" . htmlspecialchars($status) . "</span>";
                                }
                                echo $action;
                            ?>
                        </td>
                        <td class="device-info">
                            <div><strong>IP:</strong> <?php echo htmlspecialchars($log['device']['ip'] ?? '-'); ?></div>
                            <div><strong>Method:</strong> <?php echo htmlspecialchars($log['device']['request_method'] ?? '-'); ?></div>
                            <div><strong>URI:</strong> <?php echo htmlspecialchars($log['device']['request_uri'] ?? '-'); ?></div>
                            <div style="margin-top: 4px; border-top: 1px solid #334155; padding-top: 4px;">
                                <?php echo htmlspecialchars($log['device']['user_agent'] ?? '-'); ?>
                            </div>
                        </td>
                        <td>
                            <pre class="details"><?php 
                                $details = $log['details'] ?? [];
                                unset($details['status']); // already shown in action
                                if (!empty($details)) {
                                    echo htmlspecialchars(print_r($details, true));
                                } else {
                                    echo '-';
                                }
                            ?></pre>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
<?php
}
