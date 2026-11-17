<?php
require_once __DIR__ . '/bootstrap.php';

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
$orderId = trim($request['razorpay_order_id'] ?? '');
$paymentId = trim($request['razorpay_payment_id'] ?? '');
$signature = trim($request['razorpay_signature'] ?? '');

if ($bookingId <= 0 || empty($orderId) || empty($paymentId)) {
    Response::error("Missing required verification parameters: booking_id, razorpay_order_id, razorpay_payment_id.", 422);
}

try {
    $db = DB::getConnection();
    $razorpay = new RazorpayService();

    // 1. Verify cryptographic signature
    $isValid = $razorpay->verifySignature($orderId, $paymentId, $signature);
    if (!$isValid) {
        // Record failed attempt
        $failStmt = $db->prepare("
            UPDATE `transactions` 
            SET status = 'FAILED', gateway_payment_id = :payment_id 
            WHERE gateway_order_id = :order_id
        ");
        $failStmt->execute(['payment_id' => $paymentId, 'order_id' => $orderId]);

        Response::error("Cryptographic payment signature verification failed.", 400);
    }

    // 2. Fetch transaction record
    $txStmt = $db->prepare("
        SELECT id, transaction_ref, booking_id, customer_id, provider_id, gross_amount, provider_net_amount, status 
        FROM `transactions` 
        WHERE gateway_order_id = :order_id LIMIT 1
    ");
    $txStmt->execute(['order_id' => $orderId]);
    $txn = $txStmt->fetch(\PDO::FETCH_ASSOC);

    // Idempotency: If transaction is already marked SUCCESS, return safe success without re-crediting wallet
    if ($txn && strtoupper($txn['status'] ?? '') === 'SUCCESS') {
        Response::success([
            'verified' => true,
            'already_paid' => true,
            'booking_id' => $bookingId,
            'transaction_ref' => $txn['transaction_ref'],
            'amount' => (float)$txn['gross_amount'],
            'status' => 'PAID'
        ], "Payment was already verified and recorded.");
    }

    $db->beginTransaction();

    // 3. Update transaction record to SUCCESS
    $updateTx = $db->prepare("
        UPDATE `transactions` 
        SET status = 'SUCCESS', gateway_payment_id = :payment_id, gateway_signature = :sig 
        WHERE gateway_order_id = :order_id
    ");
    $updateTx->execute([
        'payment_id' => $paymentId,
        'sig' => $signature,
        'order_id' => $orderId
    ]);

    // 4. Update booking payment status in `vehicle_wash`
    $updateBooking = $db->prepare("
        UPDATE `vehicle_wash` 
        SET payment_status = 'PAID', payment_method = 'RAZORPAY' 
        WHERE id = :booking_id
    ");
    $updateBooking->execute(['booking_id' => $bookingId]);

    // Fetch booking details for status check and SSE dispatch
    $bStmt = $db->prepare("SELECT id, customer_id, provider_id, booking_number, status, total_amount FROM `vehicle_wash` WHERE id = ?");
    $bStmt->execute([$bookingId]);
    $bookingData = $bStmt->fetch(\PDO::FETCH_ASSOC);

    $isCompleted = ($bookingData && strtolower($bookingData['status']) === 'completed');
    $field = $isCompleted ? 'available_balance' : 'pending_clearance';

    // 5. If provider is attached, credit their wallet
    $providerId = $txn ? (int)$txn['provider_id'] : (int)($bookingData['provider_id'] ?? 0);
    $customerId = $txn ? (int)$txn['customer_id'] : (int)($bookingData['customer_id'] ?? 0);
    $netAmount = $txn ? (float)$txn['provider_net_amount'] : 0.0;

    if ($providerId > 0 && $netAmount > 0) {
        $walletStmt = $db->prepare("
            INSERT INTO `provider_wallets` (provider_id, {$field}, total_earned) 
            VALUES (:provider_id, :net1, :net2) 
            ON DUPLICATE KEY UPDATE 
                {$field} = {$field} + :net_dup, 
                total_earned = total_earned + :net_dup2
        ");
        $walletStmt->execute([
            'provider_id' => $providerId,
            'net1' => $netAmount,
            'net2' => $netAmount,
            'net_dup' => $netAmount,
            'net_dup2' => $netAmount
        ]);
    }

    // 6. Notify customer and provider of successful payment
    try {
        $notifRepo = new \App\Repositories\NotificationRepository();
        $formattedAmt = '₹' . number_format($txn ? (float)$txn['gross_amount'] : (float)($bookingData['total_amount'] ?? 0), 2);
        if ($customerId > 0) {
            $notifRepo->createNotification([
                'user_id' => $customerId,
                'title' => 'Payment Successful 🎉',
                'message' => "Your payment of {$formattedAmt} for booking {$bookingData['booking_number']} was successful.",
                'type' => 'payment'
            ]);
        }
        if ($providerId > 0) {
            $pStmt = $db->prepare("SELECT user_id FROM `serveasy-providers` WHERE id = ? LIMIT 1");
            $pStmt->execute([$providerId]);
            $pUserId = (int)$pStmt->fetchColumn();
            if ($pUserId > 0) {
                $notifRepo->createNotification([
                    'user_id' => $pUserId,
                    'title' => 'Payment Received 💰',
                    'message' => "Payment of {$formattedAmt} for booking {$bookingData['booking_number']} has been received and credited to your wallet balance.",
                    'type' => 'payment'
                ]);
            }
        }
    } catch (\Throwable $notifEx) {
        error_log("Failed to send payment verification notification: " . $notifEx->getMessage());
    }

    // 7. Record transactional outbox SSE event for both Customer and Provider
    try {
        $eventRepo = new \App\Repositories\EventRepository();
        $eventPayload = [
            'booking_id' => (int)$bookingId,
            'booking_number' => $bookingData['booking_number'] ?? '',
            'status' => $bookingData['status'] ?? 'completed',
            'payment_status' => 'PAID',
            'payment_method' => 'RAZORPAY',
            'amount' => $txn ? (float)$txn['gross_amount'] : (float)($bookingData['total_amount'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($customerId > 0) {
            $eventRepo->recordEvent('booking.updated', (int)$bookingId, 'CUSTOMER', $customerId, $eventPayload);
        }
        if ($providerId > 0) {
            $eventRepo->recordEvent('booking.updated', (int)$bookingId, 'PROVIDER', $providerId, $eventPayload);
        }
    } catch (\Throwable $evEx) {
        error_log("Failed to record payment verification SSE event: " . $evEx->getMessage());
    }

    if ($db->inTransaction()) {
        $db->commit();
    }

    Response::success([
        'verified' => true,
        'booking_id' => $bookingId,
        'transaction_ref' => $txn['transaction_ref'] ?? ('TXN-' . time()),
        'payment_id' => $paymentId,
        'amount' => $txn ? (float)$txn['gross_amount'] : 0.0,
        'status' => 'PAID'
    ], "Payment verified and recorded successfully.");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    Response::error("Payment verification transaction failed: " . $e->getMessage(), 500);
}

