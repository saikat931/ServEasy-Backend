<?php
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;

// Check availability / booked slots can be queried by customers during booking scheduling
$publicAction = $_GET['ajax_action'] ?? ($_GET['action'] ?? ($_POST['ajax_action'] ?? ($_POST['action'] ?? '')));
if ($publicAction === 'check_availability' || $publicAction === 'get_booked_slots') {
    $controller = new \App\Controllers\BookingController();
    $controller->checkAvailability(array_merge($_GET, $_POST));
    exit;
}

if (!Auth::check()) {
    Response::error("Authentication required", 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Parse body input
$input = [];
$json = file_get_contents('php://input');
if (!empty($json)) {
    $input = json_decode($json, true) ?? [];
}
$request = array_merge($_GET, $_POST, $input);

$controller = new \App\Controllers\BookingController();

if ($method === 'GET') {
    $action = $request['ajax_action'] ?? ($request['action'] ?? '');
    if ($action === 'get_service_charges') {
        $controller->getServiceCharges($request);
    } else if (isset($request['id'])) {
        $controller->show($request);
    } else {
        $controller->index($request);
    }
} else {
    // POST operations
    $action = $request['ajax_action'] ?? ($request['action'] ?? '');
    
    if ($action === 'update_status' || (isset($request['id']) && isset($request['status']) && empty($request['service_id']))) {
        $controller->updateStatus($request);
    } else if ($action === 'mark_paid' || $action === 'mark_booking_paid') {
        $controller->markPaid($request);
    } else if ($action === 'pay_service_charge') {
        $controller->payServiceCharge($request);
    } else if ($action === 'get_service_charges') {
        $controller->getServiceCharges($request);
    } else if ($action === 'cancel' || $action === 'cancel_booking') {
        $request['status'] = 'cancelled';
        if (empty($request['remarks']) && !empty($request['reason'])) {
            $request['remarks'] = $request['reason'];
        }
        $controller->updateStatus($request);
    } else if ($action === 'edit_booking') {
        $controller->editBooking($request);
    } else if ($action === 'update_payment') {
        if (empty($request['id']) || empty($request['payment_method'])) {
            \App\Helpers\Response::error("Booking ID and payment method are required", 422);
        }
        try {
            $bookingRepo = new \App\Repositories\BookingRepository();
            $paymentStatus = ($request['payment_method'] === 'COD') ? 'COD' : 'PAID';
            $success = $bookingRepo->updatePaymentInfo((int)$request['id'], $request['payment_method'], $paymentStatus);
            if ($success) {
                \App\Helpers\Response::success(null, "Payment method updated successfully");
            } else {
                \App\Helpers\Response::error("Failed to update payment method");
            }
        } catch (Exception $e) {
            \App\Helpers\Response::error($e->getMessage());
        }
    } else {
        // Customer booking creation
        $custId = (int)($request['customer_id'] ?? ($_SERVER['HTTP_X_CUSTOMER_ID'] ?? ($_SESSION['user_id'] ?? 0)));
        if ($custId <= 0 && Auth::hasRole('CUSTOMER')) {
            $custId = (int)Auth::id();
        }
        if ($custId <= 0 && isset($_SESSION['user_id'])) {
            $custId = (int)$_SESSION['user_id'];
        }

        if ($custId <= 0) {
            \App\Helpers\Response::error("Only customers can create service bookings", 403);
        }

        Auth::setUser([
            'user_id' => $custId,
            'role' => 'CUSTOMER'
        ]);
        $request['customer_id'] = $custId;

        $controller->store($request);
    }
}
