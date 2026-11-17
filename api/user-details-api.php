<?php
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\DB;

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

$userId = Auth::id();
$role = Auth::role();
$db = DB::getConnection();

if ($role === 'ADMIN') {
    $stmt = $db->prepare("SELECT admin_id as id, full_name, email, username as phone, role FROM admin_users WHERE admin_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
} else {
    $stmt = $db->prepare("SELECT id, full_name, email_address as email, phone_number as phone, role FROM site_user WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
}

if (!$user) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "User not found"]);
    exit;
}

echo json_encode([
    "status" => "success",
    "data" => [
        "id" => $user['id'],
        "full_name" => $user['full_name'],
        "email" => $user['email'],
        "phone" => $user['phone'],
        "role" => $role
    ]
]);
