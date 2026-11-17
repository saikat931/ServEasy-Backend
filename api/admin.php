<?php
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;

if (!Auth::check() || !Auth::hasRole('ADMIN')) {
    Response::error("Access denied: only administrators can perform this operation", 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Parse body input
$input = [];
$json = file_get_contents('php://input');
if (!empty($json)) {
    $input = json_decode($json, true) ?? [];
}
$request = array_merge($_GET, $_POST, $input);

$controller = new \App\Controllers\AdminController();

$action = $request['action'] ?? 'analytics';

if ($action === 'pending_kyc') {
    $controller->pendingKyc($request);
} elseif ($action === 'verify_kyc') {
    if ($method !== 'POST') {
        Response::error("Method not allowed", 405);
    }
    $controller->verifyKyc($request);
} elseif ($action === 'users') {
    $controller->users($request);
} elseif ($action === 'bookings') {
    $controller->bookings($request);
} else {
    $controller->analytics($request);
}
