<?php

namespace App\Controllers;

use App\Services\BookingService;
use App\Helpers\Response;
use App\Helpers\Auth;
use App\Repositories\ProviderRepository;
use Exception;

class BookingController {
    private BookingService $bookingService;
    private ProviderRepository $providerRepo;

    public function __construct() {
        $this->bookingService = new BookingService();
        $this->providerRepo = new ProviderRepository();
    }

    public function index(array $request): void {
        $userId = Auth::id();
        $role = Auth::role();

        try {
            $bookingRepo = new \App\Repositories\BookingRepository();
            $explicitProviderId = !empty($request['provider_id']) ? (int)$request['provider_id'] : 0;
            $explicitCustomerId = !empty($request['customer_id']) ? (int)$request['customer_id'] : 0;

            if ($explicitProviderId > 0) {
                $bookings = $bookingRepo->getBookingsByProvider($explicitProviderId);
            } elseif ($explicitCustomerId > 0) {
                $bookings = $bookingRepo->getBookingsByCustomer($explicitCustomerId);
            } elseif (Auth::hasRole('CUSTOMER')) {
                $bookings = $bookingRepo->getBookingsByCustomer($userId);
            } elseif (Auth::hasRole('PROVIDER')) {
                $provider = $this->providerRepo->findProviderByUserId($userId);
                if (!$provider) {
                    $bookings = [];
                } else {
                    $bookings = $bookingRepo->getBookingsByProvider((int)$provider['id']);
                }
            } elseif (Auth::hasRole('ADMIN')) {
                $bookings = $bookingRepo->getAllBookings();
            } else {
                // Strictly return empty list for unauthenticated or non-admin requests to prevent cross-user data leaks
                $bookings = [];
            }

            Response::success($bookings, "Bookings list retrieved");
        } catch (\Throwable $e) {
            Response::error($e->getMessage());
        }
    }

    public function show(array $request): void {
        if (empty($request['id'])) {
            Response::error("Booking ID is required", 422);
        }

        try {
            $bookingRepo = new \App\Repositories\BookingRepository();
            $booking = $bookingRepo->findBookingById((int)$request['id']);
            if (!$booking) {
                Response::error("Booking not found", 404);
            }

            // Authorization: verify requester belongs to booking
            $provider = $this->providerRepo->findProviderById($booking['provider_id']);
            $authUserId = (int)Auth::id();
            $isCustomer = ((int)$booking['customer_id'] === $authUserId);
            $isProvider = ($provider && ((int)($provider['user_id'] ?? 0) === $authUserId || (int)($provider['id'] ?? 0) === $authUserId));
            $isAdmin = (Auth::role() === 'ADMIN');

            if (!$isCustomer && !$isProvider && !$isAdmin) {
                Response::error("Access denied to view booking", 403);
            }

            Response::success($booking, "Booking details retrieved");
        } catch (\Throwable $e) {
            Response::error($e->getMessage());
        }
    }

    public function store(array $request): void {
        $required = ['service_id', 'schedule_date', 'schedule_time', 'address'];
        foreach ($required as $field) {
            if (empty($request[$field])) {
                Response::error("Field '{$field}' is required", 422);
            }
        }

        try {
            $request['customer_id'] = Auth::id();
            $lat = !empty($request['latitude']) ? (float)$request['latitude'] : (!empty($request['lat']) ? (float)$request['lat'] : null);
            $lng = !empty($request['longitude']) ? (float)$request['longitude'] : (!empty($request['lng']) ? (float)$request['lng'] : null);
            $request['latitude'] = $lat;
            $request['longitude'] = $lng;
            $booking = $this->bookingService->createBooking($request);
            Response::success($booking, "Booking requested successfully", 201);
        } catch (\Throwable $e) {
            Response::error($e->getMessage());
        }
    }

    public function updateStatus(array $request): void {
        if (empty($request['id']) || empty($request['status'])) {
            Response::error("Booking ID and status are required", 422);
        }

        try {
            $userId = Auth::id() ?? (int)($request['user_id'] ?? ($request['provider_id'] ?? ($request['customer_id'] ?? 0)));
            $booking = $this->bookingService->updateBookingStatus(
                (int)$request['id'],
                $request['status'],
                $userId,
                $request['remarks'] ?? ($request['reason'] ?? null)
            );
            Response::success($booking, "Booking status updated successfully");
        } catch (\Throwable $e) {
            Response::error($e->getMessage());
        }
    }

    public function editBooking(array $request): void {
        if (empty($request['id'])) {
            Response::error("Booking ID is required", 422);
        }

        try {
            $bookingRepo = new \App\Repositories\BookingRepository();
            $booking = $bookingRepo->findBookingById((int)$request['id']);
            if (!$booking) {
                Response::error("Booking not found", 404);
            }

            // Authorization check
            $userId = Auth::id() ?? (int)($request['customer_id'] ?? 0);
            if (!Auth::hasRole('ADMIN') && (int)$booking['customer_id'] !== $userId) {
                Response::error("Unauthorized to modify this booking", 403);
            }

            $currentStatus = strtolower($booking['status'] ?? 'pending');
            if (in_array($currentStatus, ['completed', 'cancelled', 'rejected'])) {
                Response::error("Cannot edit a {$currentStatus} booking", 400);
            }

            // Compare location or schedule time
            $currDate = trim($booking['schedule_date'] ?? '');
            $currTime = substr(trim($booking['schedule_time'] ?? ''), 0, 5);
            $currAddress = trim($booking['address'] ?? '');

            $newDate = isset($request['schedule_date']) && trim($request['schedule_date']) !== '' ? trim($request['schedule_date']) : $currDate;
            $newTime = isset($request['schedule_time']) && trim($request['schedule_time']) !== '' ? substr(trim($request['schedule_time']), 0, 5) : $currTime;
            $newAddress = isset($request['address']) && trim($request['address']) !== '' ? trim($request['address']) : $currAddress;

            $locationOrTimeChanged = ($newDate !== $currDate || $newTime !== $currTime || $newAddress !== $currAddress);

            $updatePayload = [
                'schedule_date' => $newDate,
                'schedule_time' => $newTime,
                'address' => $newAddress,
            ];

            if (isset($request['notes'])) {
                $updatePayload['notes'] = trim($request['notes']);
            }
            if (isset($request['vehicle_brand'])) {
                $updatePayload['vehicle_brand'] = trim($request['vehicle_brand']);
            }
            if (isset($request['vehicle_model'])) {
                $updatePayload['vehicle_model'] = trim($request['vehicle_model']);
            }
            if (isset($request['vehicle_reg_no'])) {
                $updatePayload['vehicle_reg_no'] = trim($request['vehicle_reg_no']);
            }
            if (isset($request['vehicle_type'])) {
                $updatePayload['vehicle_type'] = trim($request['vehicle_type']);
            }

            if ($locationOrTimeChanged) {
                // Changing time or location resets booking status to 'pending'
                // sending it back to provider for live acceptance/rejection
                $updatePayload['status'] = 'pending';
                $bookingRepo->updateBookingDetails((int)$booking['id'], $updatePayload);

                $remarks = "Customer changed schedule/location to {$newDate} at {$newTime}. Requires provider re-approval.";
                $bookingRepo->addStatusHistory((int)$booking['id'], 'pending', $remarks, $userId);

                // Publish transactional outbox event for provider SSE live stream
                try {
                    $db = \App\Helpers\DB::getConnection();
                    $stmt = $db->prepare("
                        INSERT INTO transactional_events (event_name, booking_id, payload, recipient_type)
                        VALUES (:ename, :bid, :payload, 'PROVIDER')
                    ");
                    $stmt->execute([
                        'ename' => 'booking.rescheduled',
                        'bid' => (int)$booking['id'],
                        'payload' => json_encode([
                            'booking_id' => (int)$booking['id'],
                            'booking_number' => $booking['booking_number'],
                            'status' => 'pending',
                            'provider_id' => (int)$booking['provider_id'],
                            'service_name' => $booking['service_name'] ?? 'Service',
                            'schedule_date' => $newDate,
                            'schedule_time' => $newTime,
                            'address' => $newAddress,
                            'remarks' => $remarks
                        ])
                    ]);
                } catch (\Throwable $te) {
                    error_log("Failed to insert outbox event: " . $te->getMessage());
                }

                $updatedBooking = $bookingRepo->findBookingById((int)$booking['id']);
                Response::success([
                    'requires_approval' => true,
                    'status' => 'pending',
                    'booking' => $updatedBooking
                ], "Schedule or location changed! Request sent back to service provider for live re-approval.");
            } else {
                // Normal details update (vehicle, notes) - status unchanged
                $bookingRepo->updateBookingDetails((int)$booking['id'], $updatePayload);
                $bookingRepo->addStatusHistory((int)$booking['id'], $currentStatus, "Customer updated vehicle details or notes", $userId);

                $updatedBooking = $bookingRepo->findBookingById((int)$booking['id']);
                Response::success([
                    'requires_approval' => false,
                    'status' => $currentStatus,
                    'booking' => $updatedBooking
                ], "Booking details updated successfully.");
            }
        } catch (\Throwable $e) {
            Response::error($e->getMessage());
        }
    }

    public function markPaid(array $request): void {
        $bookingId = (int)($request['id'] ?? ($request['booking_id'] ?? 0));
        if ($bookingId <= 0) {
            Response::error("Booking ID is required", 422);
        }
        $db = \App\Helpers\DB::getConnection();

        try {
            $bookingRepo = new \App\Repositories\BookingRepository();
            $booking = $bookingRepo->findBookingById($bookingId);
            if (!$booking) {
                Response::error("Booking not found", 404);
            }

            if (strtoupper($booking['payment_status'] ?? '') === 'PAID') {
                Response::error("Booking is already marked as PAID", 400);
            }

            $providerId = (int)$booking['provider_id'];
            $customerId = (int)$booking['customer_id'];
            $totalAmount = (float)$booking['total_amount'];
            $bookingNumber = $booking['booking_number'] ?? ('BK-' . $bookingId);

            $db->beginTransaction();

            // 1. Update booking payment status
            $bookingRepo->updatePaymentInfo($bookingId, 'COD', 'PAID');

            // 2. Record payment row in payments
            $stmtPay = $db->prepare("
                INSERT INTO `payments` (booking_id, amount, status, payment_method, gateway_transaction_id) 
                VALUES (:booking_id, :amount, 'completed', 'COD', :tx_id)
            ");
            $stmtPay->execute([
                'booking_id' => $bookingId,
                'amount' => $totalAmount,
                'tx_id' => 'COD-CASH-' . strtoupper(uniqid())
            ]);

            // 3. Record ₹20 site service charge for provider
            $serviceChargeAmount = 20.00;
            $stmtCharge = $db->prepare("
                INSERT INTO `provider_service_charges` 
                    (provider_id, booking_id, booking_number, amount, status, created_at)
                VALUES 
                    (:provider_id, :booking_id, :booking_number, :amount, 'PENDING', NOW())
            ");
            $stmtCharge->execute([
                'provider_id' => $providerId,
                'booking_id' => $bookingId,
                'booking_number' => $bookingNumber,
                'amount' => $serviceChargeAmount
            ]);

            // 4. Update provider_wallets service_charge_due
            $walletStmt = $db->prepare("
                INSERT INTO `provider_wallets` (provider_id, service_charge_due)
                VALUES (:pid, :charge)
                ON DUPLICATE KEY UPDATE service_charge_due = service_charge_due + :charge_dup
            ");
            $walletStmt->execute([
                'pid' => $providerId,
                'charge' => $serviceChargeAmount,
                'charge_dup' => $serviceChargeAmount
            ]);

            // 5. Dispatch SSE event outbox for Customer and Provider
            try {
                $eventRepo = new \App\Repositories\EventRepository();
                $eventPayload = [
                    'booking_id' => $bookingId,
                    'booking_number' => $bookingNumber,
                    'status' => $booking['status'] ?? 'completed',
                    'payment_status' => 'PAID',
                    'payment_method' => 'COD',
                    'amount' => $totalAmount,
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                if ($customerId > 0) {
                    $eventRepo->recordEvent('booking.updated', $bookingId, 'CUSTOMER', $customerId, $eventPayload);
                }
                if ($providerId > 0) {
                    $eventRepo->recordEvent('booking.updated', $bookingId, 'PROVIDER', $providerId, $eventPayload);
                }
            } catch (\Throwable $evEx) {
                error_log("Failed to dispatch markPaid SSE event: " . $evEx->getMessage());
            }

            // 6. Customer notification
            try {
                $notifRepo = new \App\Repositories\NotificationRepository();
                $notifRepo->createNotification([
                    'user_id' => $customerId,
                    'title' => 'Cash Payment Received',
                    'message' => "Provider confirmed cash receipt of ₹{$totalAmount} for booking {$bookingNumber}.",
                    'type' => 'booking'
                ]);
            } catch (\Throwable $ne) {
                error_log("Failed to create customer payment notification: " . $ne->getMessage());
            }

            $db->commit();

            $updatedBooking = $bookingRepo->findBookingById($bookingId);
            Response::success([
                'booking' => $updatedBooking,
                'service_charge_due' => $serviceChargeAmount
            ], "Booking marked as PAID! ₹{$serviceChargeAmount} site service charge recorded.");
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error($e->getMessage());
        }
    }

    public function payServiceCharge(array $request): void {
        $providerId = (int)($request['provider_id'] ?? 0);
        if (!$providerId) {
            $provider = $this->providerRepo->findProviderByUserId((int)Auth::id());
            $providerId = $provider ? (int)$provider['id'] : 0;
        }

        if (!$providerId) {
            Response::error("Provider identification required", 422);
        }

        $method = strtolower($request['payment_method'] ?? 'wallet');
        $db = \App\Helpers\DB::getConnection();

        try {
            // Calculate total pending dues
            $stmtDue = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) AS total_due 
                FROM `provider_service_charges` 
                WHERE provider_id = ? AND status = 'PENDING'
            ");
            $stmtDue->execute([$providerId]);
            $totalDue = (float)$stmtDue->fetchColumn();

            if ($totalDue <= 0) {
                Response::success(['cleared_amount' => 0, 'remaining_due' => 0], "No outstanding service charge dues.");
                return;
            }

            $db->beginTransaction();

            if ($method === 'wallet') {
                // Check provider wallet balance
                $stmtWal = $db->prepare("SELECT available_balance FROM `provider_wallets` WHERE provider_id = ?");
                $stmtWal->execute([$providerId]);
                $availBal = (float)$stmtWal->fetchColumn();

                if ($availBal < $totalDue) {
                    $db->rollBack();
                    Response::error("Insufficient wallet balance (₹" . number_format($availBal, 2) . ") to clear ₹" . number_format($totalDue, 2) . " dues. Please recharge or choose Online Payment.", 400);
                    return;
                }

                // Deduct from wallet and reset service_charge_due
                $deductStmt = $db->prepare("
                    UPDATE `provider_wallets`
                    SET available_balance = GREATEST(0, available_balance - :dues),
                        service_charge_due = GREATEST(0, service_charge_due - :dues_dup)
                    WHERE provider_id = :pid
                ");
                $deductStmt->execute([
                    'dues' => $totalDue,
                    'dues_dup' => $totalDue,
                    'pid' => $providerId
                ]);
            } else {
                // Online payment method
                $clearWalStmt = $db->prepare("
                    UPDATE `provider_wallets`
                    SET service_charge_due = 0.00
                    WHERE provider_id = ?
                ");
                $clearWalStmt->execute([$providerId]);
            }

            // Mark pending charges as PAID
            $markStmt = $db->prepare("
                UPDATE `provider_service_charges`
                SET status = 'PAID', paid_at = NOW(), payment_method = :pm
                WHERE provider_id = :pid AND status = 'PENDING'
            ");
            $markStmt->execute([
                'pm' => strtoupper($method),
                'pid' => $providerId
            ]);

            $db->commit();

            Response::success([
                'cleared_amount' => $totalDue,
                'remaining_due' => 0.00,
                'payment_method' => strtoupper($method)
            ], "Site service charge of ₹" . number_format($totalDue, 2) . " cleared! You can now accept new bookings.");
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error($e->getMessage());
        }
    }

    public function getServiceCharges(array $request): void {
        $providerId = (int)($request['provider_id'] ?? 0);
        if (!$providerId) {
            $provider = $this->providerRepo->findProviderByUserId((int)Auth::id());
            $providerId = $provider ? (int)$provider['id'] : 0;
        }

        if (!$providerId) {
            Response::error("Provider identification required", 422);
        }

        try {
            $db = \App\Helpers\DB::getConnection();
            $stmt = $db->prepare("
                SELECT id, booking_id, booking_number, amount, status, paid_at, payment_method, created_at
                FROM `provider_service_charges`
                WHERE provider_id = ?
                ORDER BY id DESC
            ");
            $stmt->execute([$providerId]);
            $charges = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmtDue = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) AS total_due 
                FROM `provider_service_charges` 
                WHERE provider_id = ? AND status = 'PENDING'
            ");
            $stmtDue->execute([$providerId]);
            $totalDue = (float)$stmtDue->fetchColumn();

            // Also get current wallet balance
            $stmtWal = $db->prepare("SELECT available_balance FROM `provider_wallets` WHERE provider_id = ?");
            $stmtWal->execute([$providerId]);
            $availBal = (float)$stmtWal->fetchColumn();

            Response::success([
                'provider_id' => $providerId,
                'total_due' => $totalDue,
                'available_balance' => $availBal,
                'can_accept_bookings' => ($totalDue <= 0),
                'charges' => $charges
            ], "Service charges retrieved successfully.");
        } catch (\Throwable $e) {
            Response::error($e->getMessage());
        }
    }

    public function checkAvailability(array $request): void {
        $providerId = (int)($request['provider_id'] ?? 0);
        $date = trim($request['date'] ?? ($request['schedule_date'] ?? ''));

        if ($providerId <= 0 || empty($date)) {
            Response::error("Valid provider_id and date are required", 422);
        }

        try {
            $data = $this->bookingService->checkProviderAvailability($providerId, $date);
            $data['provider_id'] = $providerId;
            $data['date'] = $date;
            Response::success($data, "Provider slot availability retrieved successfully.");
        } catch (\Throwable $e) {
            Response::error($e->getMessage());
        }
    }
}
