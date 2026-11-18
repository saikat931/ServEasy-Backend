<?php
require_once __DIR__ . '/bootstrap.php';
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

$conn = mysqli_connect($_ENV['DB_HOST'] ?? '127.0.0.1', $_ENV['DB_USERNAME'] ?? 'root', $_ENV['DB_PASSWORD'] ?? '', $_ENV['DB_DATABASE'] ?? 'serveasy_v2');

if (!$conn) {
    ob_clean();
    die(json_encode(["status" => "error", "message" => "Database connection failed"]));
}

header("Content-Type: application/json");

/* ================= AUTO CLEAR EXPIRED OTP ================= */
mysqli_query($conn, "
    UPDATE `serveasy-providers`
    SET otp = NULL, otp_created_at = NULL
    WHERE otp IS NOT NULL 
    AND otp_created_at < (NOW() - INTERVAL 3 MINUTE)
");

$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) {
    $decodedJson = json_decode($rawInput, true);
    if (is_array($decodedJson)) {
        $_POST = array_merge($_POST, $decodedJson);
    }
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

/* ================= DETECT EMAIL OR PHONE ================= */
function isEmail($value)
{
    return filter_var($value, FILTER_VALIDATE_EMAIL);
}

/* ================= REGISTRATION ================= */
if ($action == "register") {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (!$first_name || !$email || !$phone) {
        echo json_encode(["status" => "error", "message" => "First Name, Email, and Phone are required"]);
        exit;
    }

    $fullName = trim($first_name . ' ' . $last_name);
    $safeEmail = mysqli_real_escape_string($conn, $email);
    $safePhone = mysqli_real_escape_string($conn, $phone);
    $safeName  = mysqli_real_escape_string($conn, $fullName);

    $check = mysqli_query($conn, "SELECT id FROM `serveasy-providers` WHERE email = '$safeEmail' OR phone = '$safePhone'");
    if (mysqli_num_rows($check) > 0) {
        echo json_encode(["status" => "error", "message" => "Email or Phone already registered as provider"]);
        exit;
    }

    $otp = (string)rand(100000, 999999);
    $q = mysqli_query($conn, "
        INSERT INTO `serveasy-providers` (full_name, email, phone, otp, otp_created_at, is_active, verified_status)
        VALUES ('$safeName', '$safeEmail', '$safePhone', '$otp', NOW(), 1, 'pending')
    ");

    if ($q) {
        echo json_encode([
            "status" => "success",
            "message" => "Registration successful! OTP sent.",
            "otp" => $otp
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Registration failed: " . mysqli_error($conn)]);
    }
    exit;
}

/* ================= SEND OTP ================= */
if ($action == "send_otp") {
    $identity = trim($_POST['identity'] ?? '');

    if (!$identity) {
        echo json_encode(["status" => "error", "message" => "Email or Phone required"]);
        exit;
    }

    // Check global OTP configuration from Super Admin
    require_once __DIR__ . '/../app/services/OtpConfigService.php';
    $otpConfig = \App\Services\OtpConfigService::getConfig();

    $isInputEmail = isEmail($identity);
    if (empty($otpConfig['phone_otp_enabled']) && !$isInputEmail) {
        echo json_encode([
            "status" => "error",
            "message" => "Phone number login is closed. Please enter your email."
        ]);
        exit;
    }
    if (empty($otpConfig['email_otp_enabled']) && $isInputEmail) {
        echo json_encode([
            "status" => "error",
            "message" => "Email login is closed. Please enter your phone number."
        ]);
        exit;
    }

    $safeIdentity = mysqli_real_escape_string($conn, $identity);

    if ($isInputEmail) {
        $where_sp = "email = '$safeIdentity'";
        $col_sp = "email";
    } else {
        $clean_phone = substr(preg_replace('/[^0-9]/', '', $identity), -10);
        $where_sp = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', '') LIKE '%$clean_phone%'";
        $col_sp = "phone";
    }

    try {
        $check_sp = mysqli_query($conn, "SELECT id, full_name, otp_request_count, otp_lockout_until, otp_last_requested_at FROM `serveasy-providers` WHERE $where_sp LIMIT 1");
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        exit;
    }

    $providerRow = (mysqli_num_rows($check_sp) > 0) ? mysqli_fetch_assoc($check_sp) : null;
    $now = time();
    $requestCount = 0;

    // --- RATE LIMITING: 4 attempts max. If over, 30 minutes lockout ---
    if ($providerRow) {
        $lockoutUntil = !empty($providerRow['otp_lockout_until']) ? strtotime($providerRow['otp_lockout_until']) : null;
        $lastRequestedAt = !empty($providerRow['otp_last_requested_at']) ? strtotime($providerRow['otp_last_requested_at']) : null;
        $requestCount = (int)($providerRow['otp_request_count'] ?? 0);

        // 1. Is user currently in 30-minute lockout?
        if ($lockoutUntil && $lockoutUntil > $now) {
            $minutesRemaining = ceil(($lockoutUntil - $now) / 60);
            \App\Services\OtpConfigService::logOtpDispatch($identity, 'provider', 'BLOCKED', ($isInputEmail ? 'email' : 'phone'), 'blocked', $requestCount, "Locked out. Remaining: {$minutesRemaining} min");
            echo json_encode([
                "status" => "error",
                "message" => "Maximum OTP requests exceeded. Please wait {$minutesRemaining} minute(s) before requesting a new OTP."
            ]);
            exit;
        }

        // 2. If 30 minutes passed since last request, reset counter
        if ($lastRequestedAt && ($now - $lastRequestedAt > 1800)) {
            $requestCount = 0;
            $lockoutUntil = null;
        }

        // 3. Exhausted 4 attempts
        if ($requestCount >= 4) {
            $lockoutStr = date('Y-m-d H:i:s', $now + 1800);
            mysqli_query($conn, "UPDATE `serveasy-providers` SET otp_lockout_until = '$lockoutStr' WHERE id = " . (int)$providerRow['id']);
            \App\Services\OtpConfigService::logOtpDispatch($identity, 'provider', 'BLOCKED', ($isInputEmail ? 'email' : 'phone'), 'blocked', 4, "Exceeded 4 attempts limit; 30 min lockout applied");
            echo json_encode([
                "status" => "error",
                "message" => "Maximum OTP request limit reached (4 attempts). You must wait 30 minutes before requesting again."
            ]);
            exit;
        }
    }

    $newCount = $requestCount + 1;
    $lockoutSql = ($newCount >= 4) ? "'" . date('Y-m-d H:i:s', $now + 1800) . "'" : "NULL";
    $otp = (string) rand(100000, 999999);

    // --- DISPATCH EMAIL VIA FREE GMAIL SMTP / PHPMAILER ---
    $mailResult = null;
    if ($isInputEmail) {
        require_once __DIR__ . '/../app/helpers/MailHelper.php';
        $recipientName = $providerRow['full_name'] ?? 'Provider';
        $mailResult = \App\Helpers\MailHelper::sendOtp($identity, $otp, $recipientName, 'provider');

        if (!$mailResult['success'] && empty($mailResult['unconfigured'])) {
            echo json_encode([
                "status" => "error",
                "message" => $mailResult['message'] ?? "Failed to deliver OTP email. Please try again."
            ]);
            exit;
        }
    }

    if ($providerRow) {
        $pid = (int)$providerRow['id'];
        mysqli_query($conn, "
            UPDATE `serveasy-providers` 
            SET otp = '$otp',
                otp_created_at = NOW(),
                otp_request_count = $newCount,
                otp_lockout_until = $lockoutSql,
                otp_last_requested_at = NOW()
            WHERE id = $pid
        ");
    } else {
        mysqli_query($conn, "
            INSERT INTO `serveasy-providers` ($col_sp, otp, otp_created_at, otp_request_count, otp_lockout_until, otp_last_requested_at, verified_status, is_active)
            VALUES ('$safeIdentity', '$otp', NOW(), $newCount, $lockoutSql, NOW(), 'pending', 1)
        ");
    }

    if ($mailResult && !empty($mailResult['unconfigured'])) {
        echo json_encode([
            "status" => "success",
            "message" => "OTP generated. (SMTP credentials not yet set in backend/.env; use debug code)",
            "otp"    => $otp,
            "attempt" => $newCount,
            "max_attempts" => 4
        ]);
        exit;
    }

    $respData = [
        "status" => "success",
        "message" => "Verification code sent successfully to " . ($isInputEmail ? "your email." : "your phone.") . " (Attempt {$newCount} of 4)",
        "attempt" => $newCount,
        "max_attempts" => 4
    ];
    echo json_encode($respData);
    exit;
}

/* ================= VERIFY OTP ================= */
if ($action == "verify_otp") {
    $identity = trim($_POST['identity'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    $safeIdentity = mysqli_real_escape_string($conn, $identity);
    $safeOtp = mysqli_real_escape_string($conn, $otp);

    if (isEmail($identity)) {
        $where_sp = "email = '$safeIdentity'";
    } else {
        $clean_phone = substr(preg_replace('/[^0-9]/', '', $identity), -10);
        $where_sp = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', '') LIKE '%$clean_phone%'";
    }

    $query_sp = mysqli_query($conn, "
        SELECT * FROM `serveasy-providers`
        WHERE $where_sp
          AND otp = '$safeOtp'
          AND otp_created_at >= (NOW() - INTERVAL 3 MINUTE)
        LIMIT 1
    ");

    if (mysqli_num_rows($query_sp) > 0) {
        $provider = mysqli_fetch_assoc($query_sp);
        $providerId = (int)$provider['id'];

        // Clear OTP and reset rate limiting
        mysqli_query($conn, "
            UPDATE `serveasy-providers` 
            SET otp = NULL,
                otp_created_at = NULL,
                otp_request_count = 0,
                otp_lockout_until = NULL,
                otp_last_requested_at = NULL
            WHERE id = $providerId
        ");

        $rawName = trim($provider['full_name'] ?? '');
        $parts = explode(' ', $rawName ?: 'Service Provider', 2);
        $firstName = $parts[0] ?: 'Service';
        $lastName = $parts[1] ?? 'Provider';

        // Store session for dealer
        $_SESSION['dealer'] = [
            'id'          => $providerId,
            'provider_id' => $providerId,
            'name'        => $rawName ?: 'Service Provider',
            'email'       => $provider['email'] ?? '',
            'phone'       => $provider['phone'] ?? '',
            'role'        => 'provider'
        ];

        $accessToken = '';
        $refreshToken = '';
        try {
            $authService = new \App\Services\AuthService();
            $tokens = $authService->generateTokens([
                'id' => $providerId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $provider['email'] ?? '',
                'phone' => $provider['phone'] ?? '',
                'role' => 'PROVIDER',
                'avatar_url' => $provider['avatar_url'] ?? null
            ]);
            $accessToken = $tokens['access_token'];
            $refreshToken = $tokens['refresh_token'];

            if (!empty($accessToken)) {
                $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
                setcookie("access_token", $accessToken, [
                    'expires' => time() + 604800,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $isSecure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
        } catch (\Throwable $e) {}

        ob_clean();
        echo json_encode([
            "status" => "success",
            "access_token" => $accessToken,
            "refresh_token" => $refreshToken,
            "user" => [
                "id" => $providerId,
                "first_name" => $firstName,
                "last_name" => $lastName,
                "email" => $provider['email'] ?? '',
                "phone" => $provider['phone'] ?? '',
                "role" => "PROVIDER"
            ]
        ]);
    } else {
        ob_clean();
        echo json_encode(["status" => "error", "message" => "Invalid or expired OTP"]);
    }
    exit;
}

/* ================= VERIFY JWT TOKEN (FOR SPLIT-DOMAIN PORTAL SESSION SYNC) ================= */
if ($action == "verify_token") {
    $token = trim($_POST['access_token'] ?? ($_GET['access_token'] ?? ''));
    if (empty($token)) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
            $token = trim($m[1]);
        }
    }

    if (empty($token)) {
        ob_clean();
        echo json_encode(["status" => "error", "message" => "Access token is required"]);
        exit;
    }

    try {
        $appConfig = require dirname(__DIR__) . '/config/app.php';
        $payload = \App\Helpers\JWT::decode($token, $appConfig['jwt']['secret']);
        $providerId = (int)($payload['user_id'] ?? 0);

        if ($providerId <= 0 || strtoupper($payload['role'] ?? '') !== 'PROVIDER') {
            ob_clean();
            echo json_encode(["status" => "error", "message" => "Invalid provider token"]);
            exit;
        }

        $q = mysqli_query($conn, "SELECT id, full_name, email, phone FROM `serveasy-providers` WHERE id = $providerId LIMIT 1");
        if (!$q || mysqli_num_rows($q) === 0) {
            ob_clean();
            echo json_encode(["status" => "error", "message" => "Provider account not found"]);
            exit;
        }

        $provider = mysqli_fetch_assoc($q);
        $rawName = trim($provider['full_name'] ?? '');

        ob_clean();
        echo json_encode([
            "status" => "success",
            "dealer" => [
                "id"          => (int)$provider['id'],
                "provider_id" => (int)$provider['id'],
                "name"        => $rawName ?: 'Service Provider',
                "email"       => $provider['email'] ?? '',
                "phone"       => $provider['phone'] ?? '',
                "role"        => "provider"
            ]
        ]);
    } catch (\Throwable $e) {
        ob_clean();
        echo json_encode(["status" => "error", "message" => "Token verification failed: " . $e->getMessage()]);
    }
    exit;
}

/* ================= HELPER: CALCULATE PROFILE PROGRESS (4 PILLARS) ================= */
function calculateProviderProgress($conn, $providerId) {
    $pId = (int)$providerId;
    $provQ = mysqli_query($conn, "SELECT * FROM `serveasy-providers` WHERE id = $pId LIMIT 1");
    $prov = ($provQ && mysqli_num_rows($provQ) > 0) ? mysqli_fetch_assoc($provQ) : [];

    $phone = trim($prov['phone'] ?? '');
    $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
    $hasPhone = (!empty($phoneDigits) && strlen($phoneDigits) >= 10);

    $email = trim($prov['email'] ?? '');
    $hasEmail = (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

    $address = trim($prov['location_address'] ?? '');
    if (empty($address)) {
        $spaQ = mysqli_query($conn, "SELECT address FROM `service_providers_address` WHERE provider_id = $pId ORDER BY is_default DESC, id DESC LIMIT 1");
        if ($spaQ && $spaRow = mysqli_fetch_assoc($spaQ)) {
            $address = trim($spaRow['address'] ?? '');
        }
    }
    $hasAddress = (!empty($address) && strlen($address) >= 5);

    // ID Verification from id_verifications
    $verQ = mysqli_query($conn, "SELECT * FROM `id_verifications` WHERE user_id = $pId ORDER BY verification_id DESC LIMIT 1");
    $ver = ($verQ && mysqli_num_rows($verQ) > 0) ? mysqli_fetch_assoc($verQ) : null;

    $idStatus = 'not_submitted';
    if ($ver) {
        $idStatus = $ver['verification_status'] ?? 'under_review';
    } elseif (($prov['verified_status'] ?? '') === 'approved') {
        $idStatus = 'approved';
    }
    $hasIdProof = ($idStatus === 'approved');

    $idBadge = 'Missing';
    $idBadgeClass = 'danger';
    if ($hasIdProof || $idStatus === 'approved') {
        $idBadge = 'Approved';
        $idBadgeClass = 'success';
    } elseif ($idStatus === 'pending' || $idStatus === 'under_review') {
        $idBadge = 'Under Review';
        $idBadgeClass = 'warning';
    } elseif ($idStatus === 'rejected') {
        $idBadge = 'Rejected';
        $idBadgeClass = 'danger';
    }

    $pillars = [
        'phone' => [
            'name' => 'Phone Number',
            'done' => $hasPhone,
            'val' => $phone,
            'percent' => 25,
            'badge' => $hasPhone ? 'Verified' : 'Missing',
            'badge_class' => $hasPhone ? 'success' : 'danger',
        ],
        'email' => [
            'name' => 'Email Address',
            'done' => $hasEmail,
            'val' => $email,
            'percent' => 25,
            'badge' => $hasEmail ? 'Verified' : 'Missing',
            'badge_class' => $hasEmail ? 'success' : 'danger',
        ],
        'address' => [
            'name' => 'Address Details',
            'done' => $hasAddress,
            'val' => $address,
            'percent' => 25,
            'badge' => $hasAddress ? 'Completed' : 'Missing',
            'badge_class' => $hasAddress ? 'success' : 'danger',
        ],
        'id_proof' => [
            'name' => 'Government ID Proof',
            'done' => $hasIdProof,
            'val' => $ver ? (($ver['id_type'] ?? 'ID') . ': ' . ($ver['id_number'] ?? '')) : '',
            'status' => $idStatus,
            'percent' => 25,
            'badge' => $idBadge,
            'badge_class' => $idBadgeClass,
        ],
    ];

    $totalPercent = 0;
    $missingItems = [];
    foreach ($pillars as $key => $p) {
        if ($p['done']) {
            $totalPercent += $p['percent'];
        } else {
            if ($key === 'id_proof') {
                if ($idStatus === 'pending' || $idStatus === 'under_review') {
                    $missingItems[] = 'ID Proof (Admin Approval Pending)';
                } elseif ($idStatus === 'rejected') {
                    $missingItems[] = 'ID Proof (Rejected - Resubmit)';
                } else {
                    $missingItems[] = 'Government ID Proof';
                }
            } else {
                $missingItems[] = $p['name'];
            }
        }
    }

    return [
        'percentage' => min(100, $totalPercent),
        'is_100' => ($totalPercent >= 100),
        'pillars' => $pillars,
        'missing' => $missingItems,
        'id_status' => $idStatus,
        'ver_record' => $ver,
    ];
}

/* ================= FETCH PROVIDER ACCOUNT PROFILE ================= */
if ($action == "get_profile") {
    $providerId = (int)($_POST['provider_id'] ?? ($_GET['provider_id'] ?? 0));
    if (!$providerId) {
        echo json_encode(["status" => "error", "message" => "Provider ID required"]);
        exit;
    }

    $q = mysqli_query($conn, "
        SELECT sp.*, COALESCE(c.company_name, '') AS company_name
        FROM `serveasy-providers` sp
        LEFT JOIN `serveasy-company` c ON c.provider_id = sp.id
        WHERE sp.id = $providerId
        LIMIT 1
    ");

    if (!$q || mysqli_num_rows($q) === 0) {
        echo json_encode(["status" => "error", "message" => "Provider account not found"]);
        exit;
    }

    $row = mysqli_fetch_assoc($q);

    // Fetch landmark & pincode from service_providers_address
    $landmark = '';
    $pincode = '';
    $spaQ = mysqli_query($conn, "SELECT landmark, pincode FROM `service_providers_address` WHERE provider_id = $providerId ORDER BY is_default DESC, id DESC LIMIT 1");
    if ($spaQ && $spaRow = mysqli_fetch_assoc($spaQ)) {
        $landmark = $spaRow['landmark'] ?? '';
        $pincode = $spaRow['pincode'] ?? '';
    }

    // Fetch linked bank account
    $bankAccount = null;
    $bankQ = mysqli_query($conn, "SELECT id, account_holder_name, account_number, ifsc_code, bank_name, upi_id, is_verified FROM `provider_bank_accounts` WHERE provider_id = $providerId LIMIT 1");
    if ($bankQ && $bankRow = mysqli_fetch_assoc($bankQ)) {
        $rawAcc = $bankRow['account_number'] ?? '';
        $maskedAcc = strlen($rawAcc) > 4 ? str_repeat('*', strlen($rawAcc) - 4) . substr($rawAcc, -4) : $rawAcc;
        $bankAccount = [
            'id' => (int)$bankRow['id'],
            'account_holder_name' => $bankRow['account_holder_name'] ?? '',
            'bank_name' => $bankRow['bank_name'] ?? '',
            'account_number' => $rawAcc,
            'masked_account_number' => $maskedAcc,
            'ifsc_code' => $bankRow['ifsc_code'] ?? '',
            'upi_id' => $bankRow['upi_id'] ?? '',
            'is_verified' => (int)($bankRow['is_verified'] ?? 0),
        ];
    }

    // Fetch branch locations
    $branches = [];
    $bQ = mysqli_query($conn, "SELECT id, branch_name, address, phone, latitude, longitude FROM `serveasy-provider-branches` WHERE provider_id = $providerId ORDER BY id ASC");
    if ($bQ) {
        while ($bRow = mysqli_fetch_assoc($bQ)) {
            $branches[] = [
                'id' => (int)$bRow['id'],
                'branch_name' => $bRow['branch_name'] ?? '',
                'address' => $bRow['address'] ?? '',
                'phone' => $bRow['phone'] ?? '',
                'latitude' => $bRow['latitude'] ?? '',
                'longitude' => $bRow['longitude'] ?? '',
            ];
        }
    }

    $progress = calculateProviderProgress($conn, $providerId);

    $rawName = trim($row['full_name'] ?? '');
    $cleanName = ($rawName === 'Service Provider') ? '' : $rawName;

    // Fetch live statistics matching Service Provider index.php
    $prodCountQ = mysqli_query($conn, "SELECT COUNT(*) FROM `serveasy-services` WHERE provider_id = $providerId");
    $totalServicesCount = (int)($prodCountQ ? mysqli_fetch_row($prodCountQ)[0] : 0);

    $orderCountQ = mysqli_query($conn, "SELECT COUNT(*) FROM vehicle_wash WHERE provider_id = $providerId");
    $totalBookingsCount = (int)($orderCountQ ? mysqli_fetch_row($orderCountQ)[0] : 0);

    $salesQ = mysqli_query($conn, "SELECT SUM(total_amount) FROM vehicle_wash WHERE provider_id = $providerId AND LOWER(status) = 'completed'");
    $totalSalesAmount = (float)($salesQ ? (mysqli_fetch_row($salesQ)[0] ?: 0) : 0);

    echo json_encode([
        "status" => "success",
        "data" => [
            "id" => (int)$row['id'],
            "full_name" => $cleanName ?: ($row['company_name'] ?: 'Service Provider'),
            "display_name" => $row['company_name'] ?: ($cleanName ?: 'Service Provider'),
            "email" => $row['email'] ?? '',
            "phone" => $row['phone'] ?? '',
            "company_name" => $row['company_name'] ?? '',
            "has_company" => !empty($row['company_name']),
            "location_address" => $row['location_address'] ?? '',
            "landmark" => $landmark,
            "pincode" => $pincode,
            "latitude" => $row['latitude'] ?? '',
            "longitude" => $row['longitude'] ?? '',
            "service_range_km" => (float)($row['service_range_km'] ?? 10),
            "verified_status" => $progress['id_status'] ?? ($row['verified_status'] ?? 'pending'),
            "status" => ($row['is_active'] ?? 1) ? 'ACTIVE' : 'SUSPENDED',
            "created_at" => $row['created_at'] ?? '',
            "progress" => $progress,
            "bank_account" => $bankAccount,
            "branches" => $branches,
            "stats" => [
                "total_services" => $totalServicesCount,
                "total_bookings" => $totalBookingsCount,
                "total_sales" => $totalSalesAmount
            ]
        ]
    ]);
    exit;
}

/* ================= GET PROVIDER STATS ================= */
if ($action == "get_stats" || $action == "fetch_stats") {
    $providerId = (int)($_POST['provider_id'] ?? ($_GET['provider_id'] ?? 0));
    if (!$providerId) {
        echo json_encode(["status" => "error", "message" => "Provider ID required"]);
        exit;
    }

    $prodCountQ = mysqli_query($conn, "SELECT COUNT(*) FROM `serveasy-services` WHERE provider_id = $providerId");
    $totalServicesCount = (int)($prodCountQ ? mysqli_fetch_row($prodCountQ)[0] : 0);

    $orderCountQ = mysqli_query($conn, "SELECT COUNT(*) FROM vehicle_wash WHERE provider_id = $providerId");
    $totalBookingsCount = (int)($orderCountQ ? mysqli_fetch_row($orderCountQ)[0] : 0);

    $salesQ = mysqli_query($conn, "SELECT SUM(total_amount) FROM vehicle_wash WHERE provider_id = $providerId AND LOWER(status) = 'completed'");
    $totalSalesAmount = (float)($salesQ ? (mysqli_fetch_row($salesQ)[0] ?: 0) : 0);

    echo json_encode([
        "status" => "success",
        "data" => [
            "products" => $totalServicesCount,
            "orders" => $totalBookingsCount,
            "sales" => number_format($totalSalesAmount, 2),
            "total_services" => $totalServicesCount,
            "total_bookings" => $totalBookingsCount,
            "total_sales" => $totalSalesAmount
        ]
    ]);
    exit;
}

/* ================= REGISTER COMPANY ================= */
if ($action == "register_company") {
    $providerId = (int)($_POST['provider_id'] ?? ($_GET['provider_id'] ?? 0));
    $companyName = mysqli_real_escape_string($conn, trim($_POST['company_name'] ?? ''));

    if (!$providerId || empty($companyName)) {
        echo json_encode(["status" => "error", "message" => "Provider ID and Company Name are required"]);
        exit;
    }

    $compCheck = mysqli_query($conn, "SELECT id FROM `serveasy-company` WHERE provider_id = $providerId LIMIT 1");
    if ($compCheck && mysqli_num_rows($compCheck) > 0) {
        mysqli_query($conn, "UPDATE `serveasy-company` SET company_name = '$companyName' WHERE provider_id = $providerId");
    } else {
        mysqli_query($conn, "INSERT INTO `serveasy-company` (provider_id, company_name) VALUES ($providerId, '$companyName')");
    }

    echo json_encode([
        "status" => "success",
        "message" => "Company registered successfully! You can now add branch locations.",
        "company_name" => $companyName
    ]);
    exit;
}

/* ================= ADD BRANCH ================= */
if ($action == "add_branch") {
    $providerId = (int)($_POST['provider_id'] ?? ($_GET['provider_id'] ?? 0));
    $branchName = mysqli_real_escape_string($conn, trim($_POST['branch_name'] ?? ''));
    $address = mysqli_real_escape_string($conn, trim($_POST['address'] ?? ''));
    $phone = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));

    if (!$providerId || empty($branchName)) {
        echo json_encode(["status" => "error", "message" => "Branch name is required"]);
        exit;
    }

    // Verify provider has registered company
    $compQ = mysqli_query($conn, "SELECT id FROM `serveasy-company` WHERE provider_id = $providerId LIMIT 1");
    $compRow = ($compQ && mysqli_num_rows($compQ) > 0) ? mysqli_fetch_assoc($compQ) : null;
    if (!$compRow) {
        echo json_encode(["status" => "error", "message" => "Individual service providers cannot add branch locations. You must register a company first.", "requires_company" => true]);
        exit;
    }

    $companyId = (int)$compRow['id'];
    mysqli_query($conn, "
        INSERT INTO `serveasy-provider-branches` (provider_id, company_id, branch_name, address, phone)
        VALUES ($providerId, $companyId, '$branchName', '$address', '$phone')
    ");

    echo json_encode(["status" => "success", "message" => "Branch added successfully!"]);
    exit;
}

/* ================= SAVE PROVIDER ACCOUNT PROFILE ================= */
if ($action == "save_profile") {
    $providerId = (int)($_POST['provider_id'] ?? ($_GET['provider_id'] ?? 0));
    if (!$providerId) {
        echo json_encode(["status" => "error", "message" => "Provider ID required"]);
        exit;
    }

    $fullName = mysqli_real_escape_string($conn, trim($_POST['full_name'] ?? ''));
    $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $phone = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $companyName = mysqli_real_escape_string($conn, trim($_POST['company_name'] ?? ''));
    $locationAddress = mysqli_real_escape_string($conn, trim($_POST['location_address'] ?? ''));
    $landmark = mysqli_real_escape_string($conn, trim($_POST['landmark'] ?? ''));
    $pincode = mysqli_real_escape_string($conn, trim($_POST['pincode'] ?? ''));
    $serviceRangeKm = max(1, min(500, (float)($_POST['service_range_km'] ?? 10)));

    // Update serveasy-providers
    mysqli_query($conn, "
        UPDATE `serveasy-providers`
        SET full_name = '$fullName',
            email = '$email',
            phone = '$phone',
            location_address = '$locationAddress',
            service_range_km = $serviceRangeKm
        WHERE id = $providerId
    ");

    // Update or insert serveasy-company
    if ($companyName !== '') {
        $compCheck = mysqli_query($conn, "SELECT id FROM `serveasy-company` WHERE provider_id = $providerId LIMIT 1");
        if ($compCheck && mysqli_num_rows($compCheck) > 0) {
            mysqli_query($conn, "UPDATE `serveasy-company` SET company_name = '$companyName' WHERE provider_id = $providerId");
        } else {
            mysqli_query($conn, "INSERT INTO `serveasy-company` (provider_id, company_name) VALUES ($providerId, '$companyName')");
        }
    }

    // Update or insert service_providers_address
    if ($locationAddress !== '') {
        $spaCheck = mysqli_query($conn, "SELECT id FROM `service_providers_address` WHERE provider_id = $providerId AND is_default = 1 LIMIT 1");
        if ($spaCheck && $spaRow = mysqli_fetch_assoc($spaCheck)) {
            $spaId = (int)$spaRow['id'];
            mysqli_query($conn, "UPDATE `service_providers_address` SET address = '$locationAddress', landmark = '$landmark', pincode = '$pincode' WHERE id = $spaId");
        } else {
            mysqli_query($conn, "INSERT INTO `service_providers_address` (provider_id, label, address, landmark, pincode, is_default) VALUES ($providerId, 'Primary Location', '$locationAddress', '$landmark', '$pincode', 1)");
        }
    }

    $progress = calculateProviderProgress($conn, $providerId);

    echo json_encode([
        "status" => "success",
        "message" => "Provider profile saved successfully!",
        "progress" => $progress
    ]);
    exit;
}

/* ================= SAVE PROVIDER BANK ACCOUNT ================= */
if ($action == "save_bank_account") {
    $providerId = (int)($_POST['provider_id'] ?? ($_GET['provider_id'] ?? 0));
    if (!$providerId) {
        echo json_encode(["status" => "error", "message" => "Provider ID required"]);
        exit;
    }

    $holderName = mysqli_real_escape_string($conn, trim($_POST['account_holder_name'] ?? ''));
    $bankName = mysqli_real_escape_string($conn, trim($_POST['bank_name'] ?? ''));
    $accountNumber = mysqli_real_escape_string($conn, trim($_POST['account_number'] ?? ''));
    $ifscCode = strtoupper(mysqli_real_escape_string($conn, trim($_POST['ifsc_code'] ?? '')));
    $upiId = mysqli_real_escape_string($conn, trim($_POST['upi_id'] ?? ''));

    if (!$holderName || !$bankName || !$accountNumber || !$ifscCode) {
        echo json_encode(["status" => "error", "message" => "Account Holder Name, Bank Name, Account Number and IFSC Code are required."]);
        exit;
    }

    $chk = mysqli_query($conn, "SELECT id FROM `provider_bank_accounts` WHERE provider_id = $providerId LIMIT 1");
    if ($chk && mysqli_num_rows($chk) > 0) {
        mysqli_query($conn, "
            UPDATE `provider_bank_accounts`
            SET account_holder_name = '$holderName',
                bank_name = '$bankName',
                account_number = '$accountNumber',
                ifsc_code = '$ifscCode',
                upi_id = '$upiId'
            WHERE provider_id = $providerId
        ");
    } else {
        mysqli_query($conn, "
            INSERT INTO `provider_bank_accounts`
            (provider_id, account_holder_name, bank_name, account_number, ifsc_code, upi_id, is_verified)
            VALUES ($providerId, '$holderName', '$bankName', '$accountNumber', '$ifscCode', '$upiId', 1)
        ");
    }

    echo json_encode(["status" => "success", "message" => "Bank account details saved successfully!"]);
    exit;
}

ob_clean();
echo json_encode(["status" => "error", "message" => "Invalid request"]);
