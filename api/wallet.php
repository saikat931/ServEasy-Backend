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

$controller = new \App\Controllers\WalletController();

if ($method === 'GET') {
    $action = $request['action'] ?? '';
    if ($action === 'transactions') {
        $controller->transactions($request);
    } else {
        $controller->show($request);
    }
} else {
    // POST (add funds)
    $controller->addFunds($request);
}
