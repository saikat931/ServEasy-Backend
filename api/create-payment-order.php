<?php
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\DB;
use App\Helpers\Response;
use App\Services\RazorpayService;

// Ensure request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error("Method not allowed. Use POST.", 405);
}

// Parse body input
$input = [];
$json = file_get_contents('php://input');
if (!empty($json)) {
    $input = json_decode($json, true) ?? [];
}
$request = array_merge($_POST, $input);

$bookingId = (int)($request['booking_id'] ?? 0);
$amount = (float)($request['amount'] ?? 0.0);
$customerId = (int)($request['customer_id'] ?? 8);

if ($bookingId <= 0 || $amount <= 0) {
    Response::error("Valid booking_id and amount are required.", 422);
}

try {
    $db = DB::getConnection();

    // Verify booking exists in `vehicle_wash`
    $stmt = $db->prepare("
        SELECT id, booking_number, customer_id, provider_id, total_amount, status, payment_status 
        FROM `vehicle_wash` 
        WHERE id = :id LIMIT 1
    ");
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$booking) {
        Response::error("Booking #{$bookingId} not found.", 404);
    }

    if ($booking['payment_status'] === 'PAID') {
        Response::error("Booking #{$bookingId} is already paid.", 400);
    }

    $providerId = !empty($booking['provider_id']) ? (int)$booking['provider_id'] : null;
    $bookingNumber = $booking['booking_number'] ?? ('BK-' . $bookingId);

    // Fetch active platform fee / convenience fee configuration from serveasy-admin
    $platformRate = null;
    try {
        $feeStmt = $db->query("
            SELECT fee_amount, fee_type, is_active 
            FROM `platform_settings` 
            WHERE setting_key = 'convenience_fee' AND country_code = 'IN' 
            LIMIT 1
        ");
        $feeRow = $feeStmt ? $feeStmt->fetch(\PDO::FETCH_ASSOC) : null;
        if ($feeRow && !empty($feeRow['is_active']) && ($feeRow['fee_type'] ?? '') === 'percentage') {
            $platformRate = ((float)$feeRow['fee_amount']) / 100.0;
        }
    } catch (\Throwable $t) {}

    $razorpay = new RazorpayService();
    $breakdown = $razorpay->calculateBreakdown($amount, $platformRate, 0.02);

    $receipt = 'rcpt_' . $bookingId . '_' . time();
    $orderData = $razorpay->createOrder($amount, $receipt, [
        'booking_id' => $bookingId,
        'booking_number' => $bookingNumber,
        'customer_id' => $customerId
    ]);

    // Insert or update transaction record in `transactions`
    $transactionRef = 'TXN-' . strtoupper(bin2hex(random_bytes(6)));
    $txStmt = $db->prepare("
        INSERT INTO `transactions` (
            transaction_ref, booking_id, customer_id, provider_id, gross_amount, 
            gateway_fee, platform_commission, provider_net_amount, payment_mode, 
            gateway_name, gateway_order_id, status
        ) VALUES (
            :ref, :booking_id, :customer_id, :provider_id, :gross, 
            :fee, :comm, :net, 'RAZORPAY', 
            'RAZORPAY', :order_id, 'INITIATED'
        )
    ");
    $txStmt->execute([
        'ref' => $transactionRef,
        'booking_id' => $bookingId,
        'customer_id' => $customerId,
        'provider_id' => $providerId,
        'gross' => $breakdown['gross_amount'],
        'fee' => $breakdown['gateway_fee'],
        'comm' => $breakdown['platform_commission'],
        'net' => $breakdown['provider_net_amount'],
        'order_id' => $orderData['id']
    ]);

    Response::success([
        'order_id' => $orderData['id'],
        'amount' => $orderData['amount'],
        'currency' => $orderData['currency'],
        'key_id' => $razorpay->getKeyId(),
        'transaction_ref' => $transactionRef,
        'booking_id' => $bookingId,
        'booking_number' => $bookingNumber,
        'breakdown' => $breakdown
    ], "Razorpay order initialized successfully");

} catch (Exception $e) {
    Response::error("Failed to initialize payment order: " . $e->getMessage(), 500);
}

