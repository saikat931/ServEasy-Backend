<?php
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\DB;
use App\Helpers\Response;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Parse input from JSON body, POST or GET
$input = [];
$raw = file_get_contents('php://input');
if (!empty($raw)) {
    $input = json_decode($raw, true) ?? [];
}
$request = array_merge($_GET, $_POST, $input);

// Determine active User ID
$userId = null;
if (!empty($request['user_id'])) {
    $userId = (int)$request['user_id'];
} elseif (!empty($request['customer_id'])) {
    $userId = (int)$request['customer_id'];
} elseif (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
} elseif (Auth::id()) {
    $userId = (int)Auth::id();
} else {
    // Default fallback to user 8 for unauthenticated demo/testing
    $userId = 8;
}

try {
    $db = DB::getConnection();

    // ──────────────────────────────────────────────────────────────────────────
    // GET: Fetch Full Dynamic Profile Information from Database Columns
    // ──────────────────────────────────────────────────────────────────────────
    if ($method === 'GET') {
        // 1. Fetch user account details from site_user
        $stmtUser = $db->prepare("
            SELECT id, full_name, email_address, phone_number, is_active, created_at, updated_at 
            FROM `site_user` 
            WHERE id = :uid
            LIMIT 1
        ");
        $stmtUser->execute([':uid' => $userId]);
        $user = $stmtUser->fetch();

        if (!$user) {
            // If ID not found, attempt fetching first active user as fallback
            $fallbackStmt = $db->query("SELECT id, full_name, email_address, phone_number, is_active, created_at, updated_at FROM `site_user` ORDER BY id ASC LIMIT 1");
            $user = $fallbackStmt->fetch();
            if ($user) {
                $userId = (int)$user['id'];
            }
        }

        if (!$user) {
            Response::error("User account not found in database", 404);
        }

        // Format member since timestamp
        $createdAtTime = !empty($user['created_at']) ? strtotime($user['created_at']) : time();
        $memberSince = date('F Y', $createdAtTime);

        // 2. Fetch KYC verification status from id_verifications
        $stmtKyc = $db->prepare("
            SELECT verification_id, id_type, id_number, verification_status, created_at, verified_at
            FROM `id_verifications`
            WHERE user_id = :uid
            ORDER BY verification_id DESC
            LIMIT 1
        ");
        $stmtKyc->execute([':uid' => $userId]);
        $kyc = $stmtKyc->fetch();

        // Mask ID number for security (e.g. ****3210)
        $maskedIdNumber = null;
        if (!empty($kyc['id_number'])) {
            $rawId = (string)$kyc['id_number'];
            $maskedIdNumber = strlen($rawId) > 4 ? str_repeat('*', strlen($rawId) - 4) . substr($rawId, -4) : $rawId;
        }

        // 3. Fetch Real Booking Metrics from vehicle_wash
        $stmtBookings = $db->prepare("
            SELECT 
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
                SUM(CASE WHEN status IN ('pending', 'accepted', 'in_progress') THEN 1 ELSE 0 END) as active_bookings,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
                COALESCE(SUM(total_amount), 0) as total_booked_amount,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as total_spent
            FROM `vehicle_wash`
            WHERE customer_id = :uid
        ");
        $stmtBookings->execute([':uid' => $userId]);
        $stats = $stmtBookings->fetch();

        // 4. Fetch Saved Addresses from user_addresses
        $stmtAddresses = $db->prepare("
            SELECT id, user_id, label, address, landmark, pincode, latitude, longitude, is_default, created_at, updated_at
            FROM `user_addresses`
            WHERE user_id = :uid
            ORDER BY is_default DESC, id DESC
        ");
        $stmtAddresses->execute([':uid' => $userId]);
        $rawAddresses = $stmtAddresses->fetchAll();

        // Deduplicate addresses by address + pincode
        $seen = [];
        $addresses = [];
        foreach ($rawAddresses as $addr) {
            $key = strtolower(trim($addr['address'])) . '|' . trim($addr['pincode']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $addresses[] = [
                    'id' => (int)$addr['id'],
                    'user_id' => (int)$addr['user_id'],
                    'label' => $addr['label'] ?: 'Home',
                    'address' => $addr['address'],
                    'landmark' => $addr['landmark'] ?: '',
                    'pincode' => $addr['pincode'] ?: '',
                    'latitude' => $addr['latitude'] ? (float)$addr['latitude'] : null,
                    'longitude' => $addr['longitude'] ? (float)$addr['longitude'] : null,
                    'is_default' => (bool)$addr['is_default'],
                    'created_at' => $addr['created_at'],
                ];
            }
        }

        // 5. Fetch Recent Bookings from vehicle_wash
        $stmtRecent = $db->prepare("
            SELECT 
                id, booking_number, vehicle_type, vehicle_brand, vehicle_model, vehicle_reg_no,
                schedule_date, schedule_time, total_amount, status, payment_status, payment_method, created_at
            FROM `vehicle_wash`
            WHERE customer_id = :uid
            ORDER BY id DESC
            LIMIT 5
        ");
        $stmtRecent->execute([':uid' => $userId]);
        $recentBookings = $stmtRecent->fetchAll();

        $profilePayload = [
            'user' => [
                'id' => (int)$user['id'],
                'full_name' => $user['full_name'] ?: '',
                'email_address' => $user['email_address'] ?: '',
                'phone_number' => $user['phone_number'] ?: '',
                'is_active' => (bool)$user['is_active'],
                'created_at' => $user['created_at'],
                'updated_at' => $user['updated_at'],
                'member_since' => $memberSince,
                'role' => 'CUSTOMER',
            ],
            'kyc' => [
                'status' => $kyc ? ($kyc['verification_status'] ?: 'not_submitted') : 'not_submitted',
                'id_type' => $kyc['id_type'] ?? null,
                'masked_id' => $maskedIdNumber,
                'verified_at' => $kyc['verified_at'] ?? null,
            ],
            'stats' => [
                'total_bookings' => (int)($stats['total_bookings'] ?? 0),
                'completed_bookings' => (int)($stats['completed_bookings'] ?? 0),
                'active_bookings' => (int)($stats['active_bookings'] ?? 0),
                'cancelled_bookings' => (int)($stats['cancelled_bookings'] ?? 0),
                'total_booked_amount' => number_format((float)($stats['total_booked_amount'] ?? 0), 2, '.', ''),
                'total_spent' => number_format((float)($stats['total_spent'] ?? 0), 2, '.', ''),
                'address_count' => count($addresses),
            ],
            'addresses' => $addresses,
            'recent_bookings' => $recentBookings,
        ];

        Response::success($profilePayload, "Profile retrieved successfully");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST: Update Profile Details or Address Book
    // ──────────────────────────────────────────────────────────────────────────
    elseif ($method === 'POST') {
        $action = trim($request['action'] ?? $request['ajax_action'] ?? 'update_profile');

        // ACTION: Update Personal Info
        if ($action === 'update_profile' || $action === 'profile_update') {
            $curStmt = $db->prepare("SELECT id, full_name, email_address, phone_number FROM `site_user` WHERE id = :uid");
            $curStmt->execute([':uid' => $userId]);
            $cur = $curStmt->fetch() ?: [];

            $fullName = trim($request['full_name'] ?? '');
            if (empty($fullName)) {
                $fullName = !empty($cur['full_name']) ? $cur['full_name'] : 'User';
            }

            $email = trim($request['email_address'] ?? $request['email'] ?? '');
            if (empty($email) && !empty($cur['email_address'])) {
                $email = $cur['email_address'];
            }

            $phone = trim($request['phone_number'] ?? $request['phone'] ?? '');
            if (empty($phone) && !empty($cur['phone_number'])) {
                $phone = $cur['phone_number'];
            }

            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error("Invalid email address format", 422);
            }

            // Check if email already used by another user
            if (!empty($email) && $email !== ($cur['email_address'] ?? '')) {
                $emailCheck = $db->prepare("SELECT id FROM `site_user` WHERE email_address = :email AND id != :uid");
                $emailCheck->execute([':email' => $email, ':uid' => $userId]);
                if ($emailCheck->fetch()) {
                    Response::error("This email address is already associated with another account.", 422);
                }
            }

            // Check if phone already used by another user
            if (!empty($phone) && $phone !== ($cur['phone_number'] ?? '')) {
                $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
                if (strlen($cleanPhone) >= 10) {
                    $tenDigit = substr($cleanPhone, -10);
                    $phoneCheck = $db->prepare("SELECT id FROM `site_user` WHERE phone_number LIKE :phone AND id != :uid");
                    $phoneCheck->execute([':phone' => '%' . $tenDigit, ':uid' => $userId]);
                    if ($phoneCheck->fetch()) {
                        Response::error("This phone number is already associated with another account.", 422);
                    }
                }
            }

            // Execute PDO prepared statement
            $updateStmt = $db->prepare("
                UPDATE `site_user`
                SET 
                    full_name = :full_name,
                    email_address = :email,
                    phone_number = :phone,
                    updated_at = NOW()
                WHERE id = :uid
            ");
            $updateStmt->execute([
                ':full_name' => $fullName,
                ':email' => !empty($email) ? $email : null,
                ':phone' => !empty($phone) ? $phone : null,
                ':uid' => $userId,
            ]);

            // Fetch refreshed user record
            $refreshedStmt = $db->prepare("SELECT id, full_name, email_address, phone_number, is_active, created_at, updated_at FROM `site_user` WHERE id = :uid");
            $refreshedStmt->execute([':uid' => $userId]);
            $refreshedUser = $refreshedStmt->fetch();

            Response::success([
                'user' => [
                    'id' => (int)$refreshedUser['id'],
                    'full_name' => $refreshedUser['full_name'],
                    'email_address' => $refreshedUser['email_address'],
                    'phone_number' => $refreshedUser['phone_number'],
                    'is_active' => (bool)$refreshedUser['is_active'],
                    'created_at' => $refreshedUser['created_at'],
                    'updated_at' => $refreshedUser['updated_at'],
                ],
            ], "Profile personal details updated successfully");
        }

        // ACTION: Save Address
        elseif ($action === 'save_address' || $action === 'profile_save_address') {
            $addrId = !empty($request['id']) ? (int)$request['id'] : (!empty($request['addr_id']) ? (int)$request['addr_id'] : 0);
            $label = trim($request['label'] ?? 'Home');
            $address = trim($request['address'] ?? '');
            $landmark = trim($request['landmark'] ?? '');
            $pincode = trim($request['pincode'] ?? '');
            $lat = !empty($request['latitude']) ? (float)$request['latitude'] : null;
            $lng = !empty($request['longitude']) ? (float)$request['longitude'] : null;

            if (empty($address)) {
                Response::error("Address is required", 422);
            }

            if ($addrId > 0) {
                $stmt = $db->prepare("
                    UPDATE `user_addresses`
                    SET label = :label, address = :address, landmark = :landmark, pincode = :pincode, latitude = :lat, longitude = :lng, updated_at = NOW()
                    WHERE id = :id AND user_id = :uid
                ");
                $stmt->execute([
                    ':label' => $label,
                    ':address' => $address,
                    ':landmark' => $landmark,
                    ':pincode' => $pincode,
                    ':lat' => $lat,
                    ':lng' => $lng,
                    ':id' => $addrId,
                    ':uid' => $userId,
                ]);
            } else {
                $countCheck = $db->prepare("SELECT COUNT(*) FROM `user_addresses` WHERE user_id = :uid");
                $countCheck->execute([':uid' => $userId]);
                $isDefault = ($countCheck->fetchColumn() == 0) ? 1 : 0;

                $stmt = $db->prepare("
                    INSERT INTO `user_addresses`
                        (user_id, label, address, landmark, pincode, latitude, longitude, is_default, created_at, updated_at)
                    VALUES
                        (:uid, :label, :address, :landmark, :pincode, :lat, :lng, :is_default, NOW(), NOW())
                ");
                $stmt->execute([
                    ':uid' => $userId,
                    ':label' => $label,
                    ':address' => $address,
                    ':landmark' => $landmark,
                    ':pincode' => $pincode,
                    ':lat' => $lat,
                    ':lng' => $lng,
                    ':is_default' => $isDefault,
                ]);
                $addrId = (int)$db->lastInsertId();
            }

            Response::success(['id' => $addrId], "Address saved successfully");
        }

        // ACTION: Delete Address
        elseif ($action === 'delete_address' || $action === 'profile_delete_address') {
            $addrId = !empty($request['id']) ? (int)$request['id'] : (!empty($request['addr_id']) ? (int)$request['addr_id'] : 0);
            if (!$addrId) {
                Response::error("Address ID is required", 422);
            }

            $delStmt = $db->prepare("DELETE FROM `user_addresses` WHERE id = :id AND user_id = :uid");
            $delStmt->execute([':id' => $addrId, ':uid' => $userId]);

            // If deleted address was default, make latest default
            $checkDef = $db->prepare("SELECT id FROM `user_addresses` WHERE user_id = :uid AND is_default = 1");
            $checkDef->execute([':uid' => $userId]);
            if (!$checkDef->fetch()) {
                $db->prepare("UPDATE `user_addresses` SET is_default = 1 WHERE user_id = :uid ORDER BY id DESC LIMIT 1")->execute([':uid' => $userId]);
            }

            Response::success(null, "Address removed successfully");
        }

        // ACTION: Set Default Address
        elseif ($action === 'set_default_address' || $action === 'profile_set_default') {
            $addrId = !empty($request['id']) ? (int)$request['id'] : (!empty($request['addr_id']) ? (int)$request['addr_id'] : 0);
            if (!$addrId) {
                Response::error("Address ID is required", 422);
            }

            $db->prepare("UPDATE `user_addresses` SET is_default = 0 WHERE user_id = :uid")->execute([':uid' => $userId]);
            $db->prepare("UPDATE `user_addresses` SET is_default = 1 WHERE id = :id AND user_id = :uid")->execute([':id' => $addrId, ':uid' => $userId]);

            Response::success(null, "Default address set successfully");
        }

        else {
            Response::error("Unrecognized action: " . htmlspecialchars($action), 400);
        }
    }
} catch (\Throwable $e) {
    Response::error("Profile Error: " . $e->getMessage(), 500);
}
