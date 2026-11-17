<?php
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Parse body input (form-data, urlencoded, or JSON)
$input = [];
$json = file_get_contents('php://input');
if (!empty($json)) {
    $input = json_decode($json, true) ?? [];
}
$request = array_merge($_GET, $_POST, $input);

$controller = new \App\Controllers\ServiceController();

if ($method === 'GET') {
    if (isset($request['id'])) {
        $controller->show($request);
    } else {
        $controller->search($request);
    }
} else {
    // Write / edit operations require authentication as PROVIDER (dealer)
    if (!Auth::check() || !Auth::hasRole('PROVIDER')) {
        Response::error("Access denied: only service providers can perform this operation", 403);
    }

    $action = $request['action'] ?? '';

    if ($method === 'DELETE' || $action === 'delete') {
        $controller->destroy($request);
    } elseif ($method === 'PUT' || $action === 'update') {
        $controller->update($request);
    } else {
        $controller->store($request);
    }
}
