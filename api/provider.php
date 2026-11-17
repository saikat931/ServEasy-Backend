<?php
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;

if (!Auth::check() || !Auth::hasRole('PROVIDER')) {
    Response::error("Access denied: only service providers can perform this operation", 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Parse body input
$input = [];
$json = file_get_contents('php://input');
if (!empty($json)) {
    $input = json_decode($json, true) ?? [];
}
$request = array_merge($_GET, $_POST, $input);

$controller = new \App\Controllers\ProviderController();

if ($method === 'GET') {
    $controller->analytics($request);
} else {
    $action = $request['action'] ?? '';

    if ($action === 'kyc') {
        $controller->submitKyc($request);
    } elseif ($action === 'withdraw') {
        $controller->withdraw($request);
    } elseif ($action === 'availability' || $method === 'PUT') {
        $controller->updateAvailability($request);
    } else {
        Response::error("Invalid provider operation", 400);
    }
}
