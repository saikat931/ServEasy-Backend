<?php

namespace App\Controllers;

use App\Services\ProviderService;
use App\Repositories\ProviderRepository;
use App\Repositories\UserRepository;
use App\Repositories\BookingRepository;
use App\Helpers\Response;
use App\Helpers\DB;
use Exception;

class AdminController {
    private ProviderService $providerService;
    private ProviderRepository $providerRepo;
    private UserRepository $userRepo;
    private BookingRepository $bookingRepo;

    public function __construct() {
        $this->providerService = new ProviderService();
        $this->providerRepo = new ProviderRepository();
        $this->userRepo = new UserRepository();
        $this->bookingRepo = new BookingRepository();
    }

    public function pendingKyc(array $request): void {
        try {
            $list = $this->providerRepo->getPendingKycProviders();
            Response::success($list, "Pending KYC profiles retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function verifyKyc(array $request): void {
        if (empty($request['provider_id']) || empty($request['status'])) {
            Response::error("Provider ID and status are required", 422);
        }

        try {
            $this->providerService->verifyKyc(
                (int)$request['provider_id'],
                $request['status'],
                $request['rejection_reason'] ?? null
            );
            Response::success(null, "KYC verified successfully");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function users(array $request): void {
        try {
            $page = isset($request['page']) ? (int)$request['page'] : 1;
            $limit = isset($request['limit']) ? (int)$request['limit'] : 20;
            $list = $this->userRepo->getAllUsers($page, $limit);
            Response::success($list, "Users list retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function bookings(array $request): void {
        try {
            $page = isset($request['page']) ? (int)$request['page'] : 1;
            $limit = isset($request['limit']) ? (int)$request['limit'] : 20;
            $list = $this->bookingRepo->getAllBookings($page, $limit);
            Response::success($list, "Bookings list retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function analytics(array $request): void {
        try {
            $db = DB::getConnection();

            // Total statistics
            $totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $totalProviders = $db->query("SELECT COUNT(*) FROM providers WHERE kyc_status='approved'")->fetchColumn();
            $totalBookings = $db->query("SELECT COUNT(*) FROM vehicle_wash")->fetchColumn();
            $totalPayments = $db->query("SELECT SUM(amount) FROM payments WHERE status='completed'")->fetchColumn() ?: 0.0;
            
            // Commission calculation (15% of payment total amount default)
            $totalCommissions = $totalPayments * 0.15;

            Response::success([
                'users_count' => (int)$totalUsers,
                'providers_count' => (int)$totalProviders,
                'bookings_count' => (int)$totalBookings,
                'revenue_volume' => (float)$totalPayments,
                'commissions_collected' => (float)$totalCommissions
            ], "Global analytics snapshot compiled");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
