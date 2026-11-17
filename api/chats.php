<?php
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;

if (!Auth::check()) {
    Response::error("Authentication required", 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Parse body input
$input = [];
$json = file_get_contents('php://input');
if (!empty($json)) {
    $input = json_decode($json, true) ?? [];
}
$request = array_merge($_GET, $_POST, $input);

$controller = new \App\Controllers\ChatController();

if ($method === 'GET') {
    if (isset($request['chat_id'])) {
        $controller->messages($request);
    } elseif (isset($request['booking_id'])) {
        try {
            $chatRepo = new \App\Repositories\ChatRepository();
            $chatId = $chatRepo->findOrCreateChat((int)$request['booking_id']);
            header('Content-Type: application/json');
            echo json_encode([
                "status" => "success",
                "data" => [
                    "chat_id" => $chatId
                ]
            ]);
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit;
    } else {
        $controller->index($request);
    }
} else {
    // POST (send message)
    $controller->send($request);
}
