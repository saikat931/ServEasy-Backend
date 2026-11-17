<?php

class ErrorLogger {
    private static $logFile = __DIR__ . '/error.log';

    public static function register() {
        // Handle standard errors
        set_error_handler([__CLASS__, 'handleError']);
        
        // Handle uncaught exceptions
        set_exception_handler([__CLASS__, 'handleException']);
        
        // Handle fatal errors, parses errors that happen before shutdown
        register_shutdown_function([__CLASS__, 'handleShutdown']);
    }

    private static function getDeviceInfo() {
        return [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown'
        ];
    }

    private static function writeLog($severity, $message, $file, $line, $trace = null) {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'device' => self::getDeviceInfo(),
            'trace' => $trace
        ];

        $logLine = json_encode($logData) . PHP_EOL;
        file_put_contents(self::$logFile, $logLine, FILE_APPEND);
    }

    public static function handleError($errno, $errstr, $errfile, $errline) {
        $severity = self::getErrorName($errno);
        self::writeLog($severity, $errstr, $errfile, $errline);
        // Don't halt execution unless it's a fatal error
        return false;
    }

    public static function handleException($exception) {
        self::writeLog(
            'EXCEPTION', 
            $exception->getMessage(), 
            $exception->getFile(), 
            $exception->getLine(),
            $exception->getTraceAsString()
        );
    }

    public static function handleShutdown() {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::writeLog('FATAL', $error['message'], $error['file'], $error['line']);
        }
    }

    public static function logCustomError($message, $details = []) {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $backtrace[0] ?? [];
        self::writeLog('CUSTOM', $message, $caller['file'] ?? 'Unknown', $caller['line'] ?? 0, json_encode($details));
    }

    private static function getErrorName($type) {
        $constants = get_defined_constants(true);
        foreach ($constants['Core'] as $key => $value) {
            if (str_starts_with($key, 'E_') && $value === $type) {
                return $key;
            }
        }
        return 'UNKNOWN_ERROR';
    }
}

/**
 * Visual Dashboard to render the error logs if the file is accessed directly via browser.
 */
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    $logFile = __DIR__ . '/error.log';
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
    <title>Error Monitor Dashboard</title>
    <style>
        :root {
            --bg-color: #111827;
            --text-color: #f3f4f6;
            --card-bg: #1f2937;
            --border-color: #374151;
            --primary: #ef4444;
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
            color: #f87171;
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
            color: #9cb3c9;
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
        .badge-error { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .badge-warning { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .badge-fatal { background: rgba(185, 28, 28, 0.4); color: #fca5a5; font-weight: bold;}
        .badge-exception { background: rgba(139, 92, 246, 0.2); color: #c4b5fd; }
        
        .error-message {
            color: #fca5a5;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        .error-location {
            color: #9ca3af;
            font-size: 0.75rem;
            font-family: monospace;
        }
        pre.trace {
            margin: 0.5rem 0 0 0;
            background: rgba(0, 0, 0, 0.3);
            padding: 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            color: #9ca3af;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .device-info {
            font-size: 0.75rem;
            color: #9ca3af;
            max-width: 250px;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>🚨 Error Logs</h1>
        <div>Total Errors: <strong><?php echo count($logs); ?></strong></div>
    </div>

    <div class="logs-container">
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Type</th>
                    <th>Error & Location</th>
                    <th>Device Info</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; color: #9ca3af; padding: 2rem;">No system errors logged. Your application is running smoothly! 🎉</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="white-space: nowrap;"><?php echo htmlspecialchars($log['timestamp'] ?? '-'); ?></td>
                        <td>
                            <?php 
                                $severity = $log['severity'] ?? 'UNKNOWN'; 
                                $badgeClass = 'badge-warning';
                                if (strpos($severity, 'ERROR') !== false || $severity === 'CUSTOM') $badgeClass = 'badge-error';
                                if ($severity === 'EXCEPTION') $badgeClass = 'badge-exception';
                                if ($severity === 'FATAL') $badgeClass = 'badge-fatal';
                            ?>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($severity); ?></span>
                        </td>
                        <td>
                            <div class="error-message"><?php echo htmlspecialchars($log['message'] ?? '-'); ?></div>
                            <div class="error-location">
                                <strong>File:</strong> <?php echo htmlspecialchars($log['file'] ?? '-'); ?> <br>
                                <strong>Line:</strong> <?php echo htmlspecialchars($log['line'] ?? '-'); ?>
                            </div>
                            <?php if (!empty($log['trace'])): ?>
                                <pre class="trace"><?php echo htmlspecialchars($log['trace']); ?></pre>
                            <?php endif; ?>
                        </td>
                        <td class="device-info">
                            <div><strong>IP:</strong> <?php echo htmlspecialchars($log['device']['ip'] ?? '-'); ?></div>
                            <div><strong>URI:</strong> <?php echo htmlspecialchars($log['device']['request_uri'] ?? '-'); ?></div>
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
