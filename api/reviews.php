<?php
require_once __DIR__ . '/bootstrap.php';
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\DB;

if (!Auth::check()) {
    Response::error("Authentication required", 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $input = [];
    $json = file_get_contents('php://input');
    if (!empty($json)) {
        $input = json_decode($json, true) ?? [];
    }
    $request = array_merge($_POST, $input);
    
    $required = ['booking_id', 'rating'];
    foreach ($required as $field) {
        if (empty($request[$field])) {
            Response::error("Field '{$field}' is required", 422);
        }
    }
    
    try {
        $db = DB::getConnection();
        
        // Get service_id and provider_id from booking
        $stmt = $db->prepare("SELECT service_id, provider_id FROM vehicle_wash WHERE id = :id");
        $stmt->execute(['id' => $request['booking_id']]);
        $booking = $stmt->fetch();
        if (!$booking) {
            Response::error("Booking not found", 404);
        }
        
        $stmtIns = $db->prepare("
            INSERT INTO reviews (booking_id, customer_id, provider_id, service_id, rating, comment)
            VALUES (:booking_id, :customer_id, :provider_id, :service_id, :rating, :comment)
        ");
        $stmtIns->execute([
            'booking_id' => $request['booking_id'],
            'customer_id' => Auth::id(),
            'provider_id' => $booking['provider_id'],
            'service_id' => $booking['service_id'],
            'rating' => $request['rating'],
            'comment' => $request['comment'] ?? null
        ]);
        
        Response::success(null, "Review submitted successfully");
    } catch (Exception $e) {
        Response::error($e->getMessage());
    }
} else {
    Response::error("Method not allowed", 405);
}
