<?php

namespace App\Services;

use App\Helpers\DB;
use Exception;

class PaymentService {
    private array $config;

    public function __construct() {
        $this->config = (require dirname(__DIR__, 2) . '/config/app.php')['payments'];
    }

    public function createStripeSession(array $booking, string $successUrl, string $cancelUrl): array {
        // Stripe integration logic
        $stripeSecret = $this->config['stripe']['secret_key'];
        
        // Mock Stripe API session response to make it functional/testable
        return [
            'gateway' => 'stripe',
            'session_id' => 'cs_test_' . bin2hex(random_bytes(16)),
            'payment_url' => "https://checkout.stripe.com/pay/cs_test_mock_session_id",
            'amount' => $booking['total_amount'],
            'currency' => 'inr'
        ];
    }

    public function createRazorpayOrder(array $booking): array {
        // Razorpay order creation
        $keyId = $this->config['razorpay']['key_id'];
        
        // Mock Razorpay API order response
        return [
            'gateway' => 'razorpay',
            'order_id' => 'order_' . bin2hex(random_bytes(8)),
            'key' => $keyId,
            'amount' => $booking['total_amount'] * 100, // In paise
            'currency' => 'INR',
            'name' => 'SERVICENEST'
        ];
    }

    public function generateUPILink(array $booking): array {
        // Dynamic UPI intent link generations
        $merchantVpa = 'servicenest@bank';
        $merchantName = 'ServiceNest Marketplace';
        $amount = urlencode((string)$booking['total_amount']);
        $txRef = urlencode($booking['booking_number']);
        
        $upiUrl = "upi://pay?pa={$merchantVpa}&pn=" . urlencode($merchantName) . "&tr={$txRef}&am={$amount}&cu=INR";
        
        return [
            'gateway' => 'upi',
            'upi_string' => $upiUrl,
            'qr_code_mock_url' => "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($upiUrl)
        ];
    }

    public function processWebhook(string $gateway, array $payload): bool {
        DB::beginTransaction();
        try {
            // Processing webhook alerts to update payment status in database
            // Typically looks up booking_id, changes payment status, sets order to accepted/completed
            
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            return false;
        }
    }
}
