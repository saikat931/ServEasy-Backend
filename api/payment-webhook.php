<?php
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\DB;
use App\Helpers\Response;
use App\Services\RazorpayService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawPayload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (empty($rawPayload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

$razorpay = new RazorpayService();

// Verify webhook signature
if (!empty($signature) && !$razorpay->verifyWebhookSignature($rawPayload, $signature)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook signature']);
    exit;
}

$event = json_decode($rawPayload, true);
$eventType = $event['event'] ?? '';

try {
    $db = DB::getConnection();

    if ($eventType === 'payment.captured' || $eventType === 'order.paid') {
        $paymentEntity = $event['payload']['payment']['entity'] ?? [];
        $orderId = $paymentEntity['order_id'] ?? '';
        $paymentId = $paymentEntity['id'] ?? '';

        if (!empty($orderId)) {
            // Update transaction to SUCCESS
            $stmt = $db->prepare("
                UPDATE `transactions` 
                SET status = 'SUCCESS', gateway_payment_id = :payment_id 
                WHERE gateway_order_id = :order_id
            ");
            $stmt->execute(['payment_id' => $paymentId, 'order_id' => $orderId]);

            // Mark booking as PAID
            $stmtBooking = $db->prepare("
                UPDATE `vehicle_wash` vw
                JOIN `transactions` t ON t.booking_id = vw.id
                SET vw.payment_status = 'PAID', vw.payment_method = 'RAZORPAY'
                WHERE t.gateway_order_id = :order_id
            ");
            $stmtBooking->execute(['order_id' => $orderId]);
        }
    }

    http_response_code(200);
    echo json_encode(['status' => 'success', 'event' => $eventType]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

