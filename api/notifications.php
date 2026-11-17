<?php
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\DB;
use App\Helpers\Response;
use App\Repositories\NotificationRepository;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Parse JSON body or query input
$input = [];
$raw = file_get_contents('php://input');
if (!empty($raw)) {
    $input = json_decode($raw, true) ?? [];
}
$request = array_merge($_GET, $_POST, $input);

// Determine active User ID
$userId = null;
if (!empty($request['user_id'])) {
    $userId = (int)$request['user_id'];
} elseif (!empty($_SERVER['HTTP_X_CUSTOMER_ID'])) {
    $userId = (int)$_SERVER['HTTP_X_CUSTOMER_ID'];
} elseif (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
} elseif (Auth::id()) {
    $userId = (int)Auth::id();
} else {
    $userId = 8; // Demo / active customer default
}

$repo = new NotificationRepository();

// Helper to calculate human-readable relative time
function formatRelativeTime(string $timestamp): string {
    $time = strtotime($timestamp);
    if (!$time) return 'Recently';

    $diff = time() - $time;
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = (int)floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = (int)floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 172800) {
        return 'Yesterday at ' . date('h:i A', $time);
    } elseif ($diff < 604800) {
        $days = (int)floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('d M Y, h:i A', $time);
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// GET: Fetch Dynamic Notifications List from MySQL notifications
// ──────────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $limit = isset($request['limit']) ? (int)$request['limit'] : 50;
    $rawNotifications = $repo->getNotificationsByUserId($userId, $limit);

    $notifications = [];
    $unreadCount = 0;

    foreach ($rawNotifications as $n) {
        $isRead = (bool)$n['is_read'];
        if (!$isRead) {
            $unreadCount++;
        }

        // Extract booking number reference if present in message or title
        $bookingNumber = null;
        if (preg_match('/(SN-[A-Z0-9]+|TEST-[A-Z0-9]+)/i', $n['message'], $matches)) {
            $bookingNumber = $matches[1];
        }

        $notifications[] = [
            'id' => (int)$n['id'],
            'user_id' => (int)$n['user_id'],
            'title' => $n['title'],
            'message' => $n['message'],
            'type' => $n['type'] ?: 'general',
            'is_read' => $isRead,
            'created_at' => $n['created_at'],
            'formatted_time' => formatRelativeTime($n['created_at']),
            'booking_number' => $bookingNumber,
        ];
    }

    Response::success([
        'user_id' => $userId,
        'total_count' => count($notifications),
        'unread_count' => $unreadCount,
        'notifications' => $notifications,
    ], "Notifications fetched successfully");
}

// ──────────────────────────────────────────────────────────────────────────────
// POST: Mutation actions (mark_as_read, mark_all_read, delete)
// ──────────────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $action = $request['action'] ?? 'mark_as_read';

    if ($action === 'mark_as_read') {
        if (!isset($request['id'])) {
            Response::error("Notification ID is required", 400);
        }
        $notifId = (int)$request['id'];
        $ok = $repo->markAsRead($notifId, $userId);
        if ($ok) {
            Response::success(['id' => $notifId, 'is_read' => true], "Notification marked as read");
        } else {
            Response::error("Failed to mark notification as read");
        }
    }

    if ($action === 'mark_all_read') {
        $ok = $repo->markAllAsRead($userId);
        if ($ok) {
            Response::success(['user_id' => $userId], "All notifications marked as read");
        } else {
            Response::error("Failed to mark all notifications as read");
        }
    }

    if ($action === 'delete') {
        if (!isset($request['id'])) {
            Response::error("Notification ID is required", 400);
        }
        $notifId = (int)$request['id'];
        $ok = $repo->deleteNotification($notifId, $userId);
        if ($ok) {
            Response::success(['id' => $notifId], "Notification deleted successfully");
        } else {
            Response::error("Failed to delete notification");
        }
    }

    Response::error("Invalid action specified", 400);
}
