<?php

namespace App\Services;

use App\Repositories\BookingRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\WalletRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\EventRepository;
use App\Helpers\DB;
use Exception;

class BookingService {
    private BookingRepository $bookingRepo;
    private ServiceRepository $serviceRepo;
    private ProviderRepository $providerRepo;
    private WalletRepository $walletRepo;
    private NotificationRepository $notifRepo;
    private EventRepository $eventRepo;

    public function __construct() {
        $this->bookingRepo = new BookingRepository();
        $this->serviceRepo = new ServiceRepository();
        $this->providerRepo = new ProviderRepository();
        $this->walletRepo = new WalletRepository();
        $this->notifRepo = new NotificationRepository();
        $this->eventRepo = new EventRepository();
    }

    public function createBooking(array $data): array {
        $service = $this->serviceRepo->findServiceById($data['service_id']);
        if (!$service || !$service['is_active']) {
            throw new Exception("Service not found or inactive");
        }

        $provider = $this->providerRepo->findProviderById($service['provider_id']);
        if (!$provider || !$provider['is_available']) {
            throw new Exception("Service provider is currently unavailable");
        }

        // Validate single man provider slot conflict
        $scheduleDate = $data['schedule_date'] ?? '';
        $scheduleTime = $data['schedule_time'] ?? '';
        if (!empty($scheduleDate) && !empty($scheduleTime)) {
            $isAvailable = $this->bookingRepo->isSlotAvailable((int)$provider['id'], $scheduleDate, $scheduleTime);
            if (!$isAvailable) {
                throw new Exception("This service provider is already booked at this time. Please choose another slot.");
            }
        }

        // Calculate service provider set amount based on service or package choice
        $providerBaseAmount = (float)$service['base_price'];
        if (!empty($data['package_id']) && is_numeric($data['package_id'])) {
            $selectedPkg = null;
            if (!empty($service['packages'])) {
                foreach ($service['packages'] as $pkg) {
                    if ((int)$pkg['id'] === (int)$data['package_id']) {
                        $selectedPkg = $pkg;
                        break;
                    }
                }
            }
            if ($selectedPkg) {
                $providerBaseAmount = (float)$selectedPkg['price'];
            }
        } else {
            $data['package_id'] = null;
        }

        $extrasAmount = 0.0;
        if (!empty($data['extras'])) {
            $extrasArr = is_array($data['extras']) ? $data['extras'] : json_decode($data['extras'], true);
            if (is_array($extrasArr)) {
                foreach ($extrasArr as $ex) {
                    $extrasAmount += (float)($ex['price'] ?? 0);
                }
            }
        }
        $providerSetAmount = $providerBaseAmount + $extrasAmount;

        // Dynamic platform charge from platform_settings
        $platformCharge = 0.0;
        try {
            $settingRepo = new \App\Repositories\SettingRepository();
            $fee = $settingRepo->getConvenienceFee('IN');
            if (!empty($fee['is_active'])) {
                if (($fee['type'] ?? '') === 'percentage') {
                    $platformCharge = round(($providerSetAmount * (float)$fee['amount']) / 100, 2);
                } else {
                    $platformCharge = (float)$fee['amount'];
                }
            }
        } catch (\Throwable $t) {}

        // Total amount paid by customer = Provider Set Amount + Platform Charge
        if (!empty($data['total_amount']) && (float)$data['total_amount'] >= $providerSetAmount) {
            $totalAmount = (float)$data['total_amount'];
        } else {
            $totalAmount = round($providerSetAmount + $platformCharge, 2);
        }

        $data['provider_id'] = $provider['id'];
        $data['total_amount'] = $totalAmount;

        DB::beginTransaction();
        try {
            // Validate single man provider slot conflict with row lock
            if (!empty($scheduleDate) && !empty($scheduleTime)) {
                $isAvailable = $this->bookingRepo->isSlotAvailable((int)$provider['id'], $scheduleDate, $scheduleTime, true);
                if (!$isAvailable) {
                    DB::rollBack();
                    throw new Exception("This service provider is already booked at this time. Please choose another slot.");
                }
            }

            $bookingId = $this->bookingRepo->createBooking($data);
            $this->bookingRepo->addStatusHistory($bookingId, 'pending', 'Booking request initiated', $data['customer_id']);
            
            // Notify Provider
            try {
                $this->notifRepo->createNotification([
                    'user_id' => $provider['user_id'],
                    'title' => 'New Service Request',
                    'message' => "You have a new booking request for service: {$service['name']}.",
                    'type' => 'booking'
                ]);
            } catch (\Throwable $ne) {
                error_log("Failed to notify provider for booking {$bookingId}: " . $ne->getMessage());
            }

            // Record transactional outbox event for Provider (SSE: booking.created)
            try {
                $custStmt = DB::getConnection()->prepare("SELECT full_name, phone_number, email_address FROM `site_user` WHERE id = ?");
                $custStmt->execute([$data['customer_id']]);
                $custRow = $custStmt->fetch() ?: [];

                // Record transactional outbox event for Provider (SSE: booking.created)
                $this->eventRepo->recordEvent(
                    'booking.created',
                    $bookingId,
                    'PROVIDER',
                    (int)$provider['id'],
                    [
                        'booking_id' => $bookingId,
                        'booking_number' => $data['booking_number'] ?? '',
                        'service_id' => (int)$service['id'],
                        'service_name' => $service['name'],
                        'package_id' => !empty($data['package_id']) ? (int)$data['package_id'] : null,
                        'customer_id' => (int)$data['customer_id'],
                        'customer_name' => $custRow['full_name'] ?? 'Customer',
                        'customer_phone' => $custRow['phone_number'] ?? '',
                        'schedule_date' => $data['schedule_date'],
                        'schedule_time' => $data['schedule_time'],
                        'total_amount' => $totalAmount,
                        'status' => 'pending',
                        'vehicle_type' => $data['vehicle_type'] ?? null,
                        'vehicle_brand' => $data['vehicle_brand'] ?? null,
                        'vehicle_model' => $data['vehicle_model'] ?? null,
                        'vehicle_reg_no' => $data['vehicle_reg_no'] ?? null,
                        'location_type' => $data['location_type'] ?? 'doorstep',
                        'address' => $data['address'],
                        'extras' => $data['extras'] ?? null,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                );

                // Record transactional outbox event for Customer (SSE: booking.created)
                $this->eventRepo->recordEvent(
                    'booking.created',
                    $bookingId,
                    'CUSTOMER',
                    (int)$data['customer_id'],
                    [
                        'booking_id' => $bookingId,
                        'booking_number' => $data['booking_number'] ?? '',
                        'service_id' => (int)$service['id'],
                        'service_name' => $service['name'],
                        'package_id' => !empty($data['package_id']) ? (int)$data['package_id'] : null,
                        'provider_id' => (int)$provider['id'],
                        'provider_name' => $provider['full_name'] ?? ($provider['company_name'] ?? 'Provider'),
                        'schedule_date' => $data['schedule_date'],
                        'schedule_time' => $data['schedule_time'],
                        'total_amount' => $totalAmount,
                        'status' => 'pending',
                        'vehicle_type' => $data['vehicle_type'] ?? null,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Throwable $ee) {
                error_log("Failed to record booking.created event: " . $ee->getMessage());
            }

            DB::commit();

            return $this->bookingRepo->findBookingById($bookingId);
        } catch (Exception $e) {
            if (DB::getConnection()->inTransaction()) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    public function updateBookingStatus(int $bookingId, string $status, ?int $userId = null, ?string $remarks = null): array {
        $booking = $this->bookingRepo->findBookingById($bookingId);
        if (!$booking) {
            throw new Exception("Booking record not found");
        }

        // Basic authorization validation
        $userId = $userId ?? \App\Helpers\Auth::id() ?? 0;
        $provider = $this->providerRepo->findProviderById((int)$booking['provider_id']);
        
        $isCustomer = ((int)$booking['customer_id'] === (int)$userId);
        $isProvider = ($provider && ((int)($provider['user_id'] ?? 0) === (int)$userId || (int)($provider['id'] ?? 0) === (int)$userId)) || ((int)$booking['provider_id'] === (int)$userId);
        $isAdmin = \App\Helpers\Auth::hasRole('ADMIN') || ($userId === 1);

        if (!$isCustomer && !$isProvider && !$isAdmin) {
            throw new Exception("Unauthorized status operation");
        }

        DB::beginTransaction();
        try {
            $statusMap = [
                'cancel' => 'cancelled',
                'canceled' => 'cancelled',
                'cancelled' => 'cancelled',
                'reject' => 'rejected',
                'rejected' => 'rejected',
                'decline' => 'rejected',
                'declined' => 'rejected',
                'accept' => 'accepted',
                'accepted' => 'accepted',
                'confirm' => 'confirmed',
                'confirmed' => 'confirmed',
                'start' => 'in_progress',
                'in_progress' => 'in_progress',
                'inprogress' => 'in_progress',
                'complete' => 'completed',
                'completed' => 'completed'
            ];
            $rawStatus = strtolower(trim($status));
            $targetStatus = $statusMap[$rawStatus] ?? $rawStatus;
            $status = $targetStatus;
            $currentStatus = strtolower(trim($booking['status']));

            // Role authorization per transition target
            if (in_array($targetStatus, ['accepted', 'confirmed', 'assigned', 'on_the_way', 'in_progress', 'completed', 'rejected']) && !$isProvider && !$isAdmin) {
                throw new Exception("Only the assigned service provider or an administrator can update booking to '{$targetStatus}'");
            }
            if ($targetStatus === 'cancelled' && !$isCustomer && !$isProvider && !$isAdmin) {
                throw new Exception("You are not authorized to cancel this booking");
            }

            if ($currentStatus === $targetStatus) {
                throw new Exception("Booking is already in {$currentStatus} status");
            }
            
            // Check status transition logic
            if ($targetStatus === 'accepted' || $targetStatus === 'confirmed') {
                if ($currentStatus !== 'pending') {
                    throw new Exception("Only pending bookings can be accepted/confirmed");
                }

                // Check if provider has unpaid site service charge dues
                $providerId = (int)$booking['provider_id'];
                $dueStmt = DB::getConnection()->prepare("
                    SELECT COALESCE(SUM(amount), 0) AS total_due 
                    FROM `provider_service_charges` 
                    WHERE provider_id = ? AND status = 'PENDING'
                ");
                $dueStmt->execute([$providerId]);
                $totalDue = (float)$dueStmt->fetchColumn();
                if ($totalDue > 0) {
                    throw new Exception("OUTSTANDING_SERVICE_CHARGE: You have unpaid site service charge dues of ₹" . number_format($totalDue, 2) . ". Please pay your service charge before accepting new bookings.");
                }
            }
            if ($targetStatus === 'rejected') {
                if ($currentStatus === 'in_progress') {
                    // Normalize to cancelled if job was already in progress
                    $targetStatus = 'cancelled';
                    $status = 'cancelled';
                } elseif (!in_array($currentStatus, ['pending', 'accepted', 'confirmed'])) {
                    throw new Exception("Cannot reject booking in {$currentStatus} status. Only pending or accepted bookings can be declined.");
                }
            }
            if ($targetStatus === 'assigned' && !in_array($currentStatus, ['pending', 'accepted', 'confirmed'])) {
                throw new Exception("Booking must be pending or accepted/confirmed to assign staff");
            }
            if ($targetStatus === 'on_the_way' && !in_array($currentStatus, ['accepted', 'confirmed', 'assigned'])) {
                throw new Exception("Booking must be confirmed or staff assigned to go on the way");
            }
            if ($targetStatus === 'in_progress' && !in_array($currentStatus, ['accepted', 'confirmed', 'assigned', 'on_the_way'])) {
                throw new Exception("Booking must be confirmed, assigned, or on the way to start");
            }
            if ($targetStatus === 'completed' && $currentStatus !== 'in_progress') {
                throw new Exception("Booking must be in progress to complete");
            }
            if ($targetStatus === 'cancelled' && in_array($currentStatus, ['completed', 'rejected', 'cancelled'])) {
                throw new Exception("Cannot cancel a {$currentStatus} booking");
            }

            // Update status
            $this->bookingRepo->updateStatus($bookingId, $status);
            $this->bookingRepo->addStatusHistory($bookingId, $status, $remarks, (int)$userId);

            if ($targetStatus === 'completed') {
                // Settle payments & transfer money to provider
                $this->settleBookingPayment($booking);
            } elseif ($targetStatus === 'rejected' || $targetStatus === 'cancelled') {
                // Revert pending escrow clearance if previously charged online
                try {
                    $txnStmt = DB::getConnection()->prepare("
                        SELECT id, provider_id, provider_net_amount 
                        FROM `transactions` 
                        WHERE booking_id = ? AND status = 'SUCCESS' 
                        LIMIT 1
                    ");
                    $txnStmt->execute([$bookingId]);
                    $onlineTxn = $txnStmt->fetch(\PDO::FETCH_ASSOC);
                    if ($onlineTxn && (float)$onlineTxn['provider_net_amount'] > 0) {
                        $pId = (int)$onlineTxn['provider_id'];
                        $net = (float)$onlineTxn['provider_net_amount'];
                        $revStmt = DB::getConnection()->prepare("
                            UPDATE `provider_wallets`
                            SET pending_clearance = GREATEST(0, pending_clearance - ?),
                                total_earned = GREATEST(0, total_earned - ?)
                            WHERE provider_id = ?
                        ");
                        $revStmt->execute([$net, $net, $pId]);
                    }
                } catch (\Throwable $revEx) {
                    error_log("Failed to reverse pending escrow for booking {$bookingId}: " . $revEx->getMessage());
                }
            }

            // Notify corresponding parties
            try {
                if ($targetStatus === 'accepted' || $targetStatus === 'confirmed') {
                    $this->notifRepo->createNotification([
                        'user_id' => $booking['customer_id'],
                        'title' => 'Booking Confirmed',
                        'message' => "Your booking {$booking['booking_number']} has been confirmed.",
                        'type' => 'booking'
                    ]);
                } elseif ($targetStatus === 'rejected' || $targetStatus === 'cancelled') {
                    $title = ($targetStatus === 'cancelled') ? 'Booking Cancelled' : 'Booking Declined';
                    $actionDesc = ($targetStatus === 'cancelled') ? 'cancelled' : 'declined by the provider';
                    $this->notifRepo->createNotification([
                        'user_id' => $booking['customer_id'],
                        'title' => $title,
                        'message' => "Your booking {$booking['booking_number']} was {$actionDesc}." . (!empty($remarks) ? " Reason: {$remarks}" : ""),
                        'type' => 'booking'
                    ]);
                    if ($isCustomer && !empty($booking['provider_id'])) {
                        $this->notifRepo->createNotification([
                            'user_id' => (int)$booking['provider_id'],
                            'title' => 'Booking Cancelled by Customer',
                            'message' => "Booking {$booking['booking_number']} has been cancelled by the customer." . (!empty($remarks) ? " Reason: {$remarks}" : ""),
                            'type' => 'booking'
                        ]);
                    }
                } elseif ($targetStatus === 'completed') {
                    $this->notifRepo->createNotification([
                        'user_id' => $booking['customer_id'],
                        'title' => 'Service Completed',
                        'message' => "Your service request {$booking['booking_number']} has been completed. Please write a review.",
                        'type' => 'booking'
                    ]);
                }
            } catch (\Throwable $ne) {
                error_log("Failed to send booking status notification for booking {$bookingId}: " . $ne->getMessage());
            }

            // Record transactional outbox event for Customer & Provider (SSE events)
            try {
                $eventName = 'booking.updated';
                if ($targetStatus === 'accepted' || $targetStatus === 'confirmed') {
                    $eventName = 'booking.accepted';
                } elseif ($targetStatus === 'rejected') {
                    $eventName = 'booking.rejected';
                } elseif ($targetStatus === 'cancelled') {
                    $eventName = 'booking.cancelled';
                }

                $eventPayload = [
                    'booking_id' => $bookingId,
                    'booking_number' => $booking['booking_number'],
                    'status' => $targetStatus,
                    'remarks' => $remarks,
                    'provider_id' => (int)$booking['provider_id'],
                    'provider_name' => $provider['full_name'] ?? ($provider['company_name'] ?? 'Provider'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                // Publish to Customer
                $this->eventRepo->recordEvent($eventName, $bookingId, 'CUSTOMER', (int)$booking['customer_id'], $eventPayload);

                // Publish to Provider
                $this->eventRepo->recordEvent('booking.updated', $bookingId, 'PROVIDER', (int)$booking['provider_id'], $eventPayload);
            } catch (\Throwable $ee) {
                error_log("Failed to record booking status event: " . $ee->getMessage());
            }

            DB::commit();
            return $this->bookingRepo->findBookingById($bookingId);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function settleBookingPayment(array $booking): void {
        $paymentMethod = strtoupper(trim($booking['payment_method'] ?? 'COD'));
        $totalAmount = (float)$booking['total_amount'];

        // Escrow release for online payments (Razorpay)
        try {
            $db = DB::getConnection();
            $txnStmt = $db->prepare("
                SELECT id, provider_id, provider_net_amount, status 
                FROM `transactions` 
                WHERE booking_id = ? AND status = 'SUCCESS' 
                LIMIT 1
            ");
            $txnStmt->execute([$booking['id']]);
            $onlineTxn = $txnStmt->fetch(\PDO::FETCH_ASSOC);

            if ($onlineTxn && (float)$onlineTxn['provider_net_amount'] > 0) {
                $pId = (int)$onlineTxn['provider_id'];
                $net = (float)$onlineTxn['provider_net_amount'];
                // Move from pending_clearance to available_balance in provider_wallets
                $releaseStmt = $db->prepare("
                    UPDATE `provider_wallets`
                    SET pending_clearance = GREATEST(0, pending_clearance - ?),
                        available_balance = available_balance + ?
                    WHERE provider_id = ?
                ");
                $releaseStmt->execute([$net, $net, $pId]);
            }
        } catch (\Throwable $escrowEx) {
            error_log("Escrow release error for booking {$booking['id']}: " . $escrowEx->getMessage());
        }

        if ($paymentMethod === 'COD' || empty($paymentMethod)) {
            // Cash On Delivery - customer can pay online or offline directly to provider.
            // Do NOT mark as PAID automatically on completion. Status remains COD / unpaid
            // until user pays online or provider marks cash collected.
            return;
        }

        // Digital / Wallet payment settlement
        try {
            $customerWallet = $this->walletRepo->findWalletByUserId($booking['customer_id']);
            if (!$customerWallet) {
                $walletId = $this->walletRepo->createWallet($booking['customer_id']);
                $customerWallet = ['id' => $walletId, 'balance' => 0.00];
            }

            $provider = $this->providerRepo->findProviderById($booking['provider_id']);
            $providerUserId = $provider ? (int)($provider['user_id'] ?? $provider['id']) : 0;
            $providerWallet = $providerUserId ? $this->walletRepo->findWalletByUserId($providerUserId) : null;
            if (!$providerWallet && $providerUserId) {
                $pWalletId = $this->walletRepo->createWallet($providerUserId);
                $providerWallet = ['id' => $pWalletId, 'balance' => 0.00];
            }

            // Deduct from customer wallet if balance is available
            if ((float)$customerWallet['balance'] >= $totalAmount) {
                $this->walletRepo->updateBalance(
                    $customerWallet['id'],
                    $totalAmount,
                    'debit',
                    "Payment for booking {$booking['booking_number']}",
                    (string)$booking['id']
                );
            }

            // Calculate commissions
            $commissionRate = isset($provider['commission_rate']) ? (float)$provider['commission_rate'] : 0.15;
            $commissionAmount = $totalAmount * $commissionRate;
            $providerPayout = $totalAmount - $commissionAmount;

            if ($providerWallet) {
                $this->walletRepo->updateBalance(
                    $providerWallet['id'],
                    $providerPayout,
                    'credit',
                    "Payout for booking {$booking['booking_number']} (Commission: {$commissionAmount})",
                    (string)$booking['id']
                );
            }

            // Update booking payment info
            $this->bookingRepo->updatePaymentInfo($booking['id'], $paymentMethod, 'PAID');

            // Record payment record in database
            $db = DB::getConnection();
            $stmtPay = $db->prepare("
                INSERT INTO `payments` (booking_id, amount, status, payment_method, gateway_transaction_id) 
                VALUES (:booking_id, :amount, 'completed', :payment_method, :tx_id)
            ");
            $stmtPay->execute([
                'booking_id' => $booking['id'],
                'amount' => $totalAmount,
                'payment_method' => strtolower($paymentMethod),
                'tx_id' => 'TX-' . strtoupper(uniqid())
            ]);
        } catch (\Throwable $we) {
            error_log("Wallet settlement warning for booking {$booking['id']}: " . $we->getMessage());
            $this->bookingRepo->updatePaymentInfo($booking['id'], $paymentMethod, 'PAID');
        }
    }

    /**
     * Check provider availability for a specific date (booked slots for single providers)
     */
    public function checkProviderAvailability(int $providerId, string $date): array {
        return $this->bookingRepo->getProviderBookedSlots($providerId, $date);
    }
}
