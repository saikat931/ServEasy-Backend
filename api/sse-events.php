<?php
/**
 * ServEasy - Real-Time Server-Sent Events (SSE) Endpoint
 * Handles live event dispatch for Customers and Providers with:
 * 1. Immediate session release (session_write_close) to prevent session lock blocking
 * 2. Timeboxed auto-recycling (25s) to prevent Apache worker pool exhaustion
 * 3. Anti-buffering headers (X-Accel-Buffering, Cache-Control, no-transform)
 * 4. Transactional outbox querying (booking_events) with Last-Event-ID support
 */

require_once __DIR__ . '/bootstrap.php';

use App\Repositories\EventRepository;
use App\Helpers\Auth;
use App\Helpers\JWT;

// Disable all execution time limits for this long-lived script
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ignore_user_abort(true);

// ── 1. Authentication & Recipient Resolution ─────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$recipientType = null;
$recipientId = null;

$requestedChannel = strtolower(trim($_GET['channel'] ?? ''));

// Check for JWT token via query param or cookie (since native EventSource doesn't support custom headers)
$token = $_GET['token'] ?? ($_COOKIE['access_token'] ?? null);
if ($token) {
    $configPath = dirname(__DIR__) . '/config/app.php';
    if (file_exists($configPath)) {
        $config = require $configPath;
        try {
            $payload = JWT::decode($token, $config['jwt']['secret']);
            if ($payload && isset($payload['user_id'])) {
                $userRole = strtoupper($payload['role'] ?? 'CUSTOMER');
                if ($userRole === 'PROVIDER' || $requestedChannel === 'provider') {
                    $recipientType = 'PROVIDER';
                    $recipientId = (int)$payload['user_id'];
                } else {
                    $recipientType = 'CUSTOMER';
                    $recipientId = (int)$payload['user_id'];
                }
            }
        } catch (\Throwable $e) {}
    }
}

// Fallback to PHP Session Auth if not resolved via token
if (!$recipientType) {
    if ($requestedChannel === 'provider' || isset($_SESSION['dealer'])) {
        if (!empty($_SESSION['dealer']['id'])) {
            $recipientType = 'PROVIDER';
            $recipientId = (int)$_SESSION['dealer']['id'];
        } elseif (!empty($_SESSION['dealer_id'])) {
            $recipientType = 'PROVIDER';
            $recipientId = (int)$_SESSION['dealer_id'];
        } elseif (!empty($_GET['provider_id'])) {
            $recipientType = 'PROVIDER';
            $recipientId = (int)$_GET['provider_id'];
        } elseif (Auth::check() && Auth::hasRole('PROVIDER')) {
            $recipientType = 'PROVIDER';
            $recipientId = (int)Auth::id();
        }
    } else {
        if (!empty($_SESSION['user_id'])) {
            $recipientType = 'CUSTOMER';
            $recipientId = (int)$_SESSION['user_id'];
        } elseif (!empty($_GET['customer_id'])) {
            $recipientType = 'CUSTOMER';
            $recipientId = (int)$_GET['customer_id'];
        } elseif (Auth::check()) {
            $recipientType = 'CUSTOMER';
            $recipientId = (int)Auth::id();
        }
    }
}

// ── 2. CRUCIAL: Release Session Lock Immediately ─────────────────────────────
// Disadvantage 2 Solution: Closing the session write lock ensures the client
// can execute POST requests, browse other tabs, and navigate with zero blocking!
session_write_close();

// Reject unauthenticated requests
if (!$recipientType || !$recipientId) {
    header('HTTP/1.1 401 Unauthorized');
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Authentication required to establish real-time event stream.'
    ]);
    exit();
}

// ── 2b. Fast JSON Polling Mode (for Mobile Apps / Non-EventSource Clients) ────
if ((isset($_GET['format']) && $_GET['format'] === 'json') || isset($_GET['poll']) || isset($_GET['mobile'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        header("Access-Control-Allow-Credentials: true");
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        header("Access-Control-Allow-Origin: {$scheme}://{$host}");
        header("Access-Control-Allow-Credentials: true");
    }
    header("Vary: Origin");

    $eventRepo = new EventRepository();
    $lastEventId = null;
    if (isset($_GET['last_event_id']) && $_GET['last_event_id'] !== '') {
        $lastEventId = (int)$_GET['last_event_id'];
    }

    // On fresh connection without last_event_id, start from latest event to initialize pointer
    if ($lastEventId === null) {
        $latestId = $eventRepo->getLatestEventIdForRecipient($recipientType, $recipientId);
        echo json_encode([
            'success' => true,
            'events' => [],
            'last_event_id' => $latestId,
            'server_time' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    $events = $eventRepo->getEventsForRecipient($recipientType, $recipientId, $lastEventId, 25);
    $newLastEventId = !empty($events) ? (int)end($events)['id'] : $lastEventId;

    echo json_encode([
        'success' => true,
        'events' => $events,
        'last_event_id' => $newLastEventId,
        'server_time' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// ── 3. Anti-Buffering Streaming Headers ──────────────────────────────────────
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Tells Nginx / Reverse Proxies not to buffer chunks
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    header("Access-Control-Allow-Origin: {$scheme}://{$host}");
    header("Access-Control-Allow-Credentials: true");
}
header("Vary: Origin");

// Clear all PHP output buffers so chunks flush immediately to the network
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

// ── 4. Track Last-Event-ID for Replay & Missed-Event Recovery ─────────────────
$eventRepo = new EventRepository();

$lastEventId = null;
if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $lastEventId = (int)$_SERVER['HTTP_LAST_EVENT_ID'];
} elseif (isset($_GET['last_event_id']) && $_GET['last_event_id'] !== '') {
    $lastEventId = (int)$_GET['last_event_id'];
}

// On fresh connection or browser reload without Last-Event-ID, start from the latest event
// to prevent historical events from past bookings from replaying on page reload
if ($lastEventId === null) {
    $lastEventId = $eventRepo->getLatestEventIdForRecipient($recipientType, $recipientId);
}

// Tell browser to reconnect in 1000ms if stream drops
echo "retry: 1000\n";
echo ": connection established for {$recipientType} #{$recipientId}\n\n";
@flush();

// ── 5. Timeboxed Event Streaming Loop (Disadvantage 1 Solution) ───────────────
// Keep each connection open for 25 seconds max, then exit cleanly.
// Browser's EventSource automatically reconnects with Last-Event-ID in 1 second.
// This recycles Apache worker threads and drops abandoned tabs instantly!
$startTime = time();
$maxDuration = 25; // 25 seconds
$lastPingTime = time();

while ((time() - $startTime) < $maxDuration) {
    // Check if the client closed the tab / aborted connection
    if (connection_aborted()) {
        break;
    }

    // Query tiny indexed outbox table (executes in < 0.2ms)
    try {
        $events = $eventRepo->getEventsForRecipient($recipientType, $recipientId, $lastEventId, 20);
        if (!empty($events)) {
            foreach ($events as $evt) {
                $lastEventId = (int)$evt['id'];
                echo "id: {$evt['id']}\n";
                echo "event: {$evt['event_name']}\n";
                echo "data: " . json_encode($evt['payload'], JSON_UNESCAPED_UNICODE) . "\n\n";
            }
            @flush();
        }
    } catch (\Throwable $e) {
        error_log("SSE stream query error: " . $e->getMessage());
    }

    // Send heartbeat comment ping every 10 seconds to keep connection alive through proxies
    if ((time() - $lastPingTime) >= 10) {
        echo ": ping\n\n";
        @flush();
        $lastPingTime = time();
    }

    // Sleep for 800ms before checking for new events
    usleep(800000);
}

// Stream timebox reached - send advisory comment and close cleanly
echo ": cycle_refresh\n\n";
@flush();
exit();
