<?php
require_once __DIR__ . '/bootstrap.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../logs/systemlog.php';
require_once __DIR__ . '/../logs/errorlog.php';

ErrorLogger::register();

// include "./Backend/Config/db_connection.php";


$conn = mysqli_connect(
    $_ENV['DB_HOST'] ?? '127.0.0.1',
    $_ENV['DB_USERNAME'] ?? 'root',
    $_ENV['DB_PASSWORD'] ?? '',
    $_ENV['DB_DATABASE'] ?? 'serveasy_v2'
);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

if (!$conn) {
    die(json_encode(["status" => "error", "message" => "Database connection failed"]));
}

header("Content-Type: application/json");

    /* ================= AUTO CLEAR EXPIRED OTP ================= */

mysqli_query($conn, "
    UPDATE site_user 
    SET otp = NULL, otp_created_at = NULL
    WHERE otp IS NOT NULL 
    AND otp_created_at < (NOW() - INTERVAL 3 MINUTE)
");

$action = $_POST['action'] ?? '';

/* ================= REGISTRATION ================= */

if ($action == "register") {
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';

    if (!$first_name || !$email || !$phone) {
        echo json_encode(["status" => "error", "message" => "First Name, Email, and Phone are required"]);
        exit;
    }

    $fullName = trim($first_name . ' ' . $last_name);

    $check = mysqli_query($conn, "SELECT id FROM site_user WHERE email_address = '$email' OR phone_number = '$phone'");
    if (mysqli_num_rows($check) > 0) {
        echo json_encode(["status" => "error", "message" => "Email or Phone already registered"]);
        exit;
    }

    $otp = rand(100000, 999999);
    $q = mysqli_query($conn, "
        INSERT INTO site_user (full_name, email_address, phone_number, otp, otp_created_at, password)
        VALUES ('$fullName', '$email', '$phone', '$otp', NOW(), '')
    ");

    if ($q) {
        echo json_encode([
            "status" => "success",
            "message" => "Registration successful! OTP sent.",
            "otp" => $otp
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Registration failed"]);
    }
    exit;
}

/* ================= DETECT EMAIL OR PHONE ================= */
function isEmail($value)
{
    return filter_var($value, FILTER_VALIDATE_EMAIL);
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

    if ($isInputEmail) {
        $column = "email_address";
        $safeIdentity = mysqli_real_escape_string($conn, $identity);
        $where_clause = "email_address = '$safeIdentity'";
    } else {
        $column = "phone_number";
        $clean_phone = substr(preg_replace('/[^0-9]/', '', $identity), -10);
        $safeIdentity = mysqli_real_escape_string($conn, $identity);
        $where_clause = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone_number, ' ', ''), '+', ''), '-', ''), '(', ''), ')', '') LIKE '%$clean_phone%'";
    }

    try {
        $check = mysqli_query($conn, "SELECT id, full_name, otp_request_count, otp_lockout_until, otp_last_requested_at FROM site_user WHERE $where_clause LIMIT 1");
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        exit;
    }

    $userRow = (mysqli_num_rows($check) > 0) ? mysqli_fetch_assoc($check) : null;
    $now = time();
    $requestCount = 0;

    // --- RATE LIMITING: 4 attempts max. If over, 30 minutes lockout ---
    if ($userRow) {
        $lockoutUntil = !empty($userRow['otp_lockout_until']) ? strtotime($userRow['otp_lockout_until']) : null;
        $lastRequestedAt = !empty($userRow['otp_last_requested_at']) ? strtotime($userRow['otp_last_requested_at']) : null;
        $requestCount = (int)($userRow['otp_request_count'] ?? 0);

        // 1. Check if user is currently locked out for 30 minutes
        if ($lockoutUntil && $lockoutUntil > $now) {
            $minutesRemaining = ceil(($lockoutUntil - $now) / 60);
            \App\Services\OtpConfigService::logOtpDispatch($identity, 'customer', 'BLOCKED', ($isInputEmail ? 'email' : 'phone'), 'blocked', $requestCount, "Locked out. Remaining: {$minutesRemaining} min");
            echo json_encode([
                "status" => "error",
                "message" => "Maximum OTP requests exceeded. Please wait {$minutesRemaining} minute(s) before requesting a new OTP."
            ]);
            exit;
        }

        // 2. If 30 minutes have passed since the last request, reset attempt count
        if ($lastRequestedAt && ($now - $lastRequestedAt > 1800)) {
            $requestCount = 0;
            $lockoutUntil = null;
        }

        // 3. If user has already exhausted 4 attempts
        if ($requestCount >= 4) {
            $lockoutStr = date('Y-m-d H:i:s', $now + 1800);
            mysqli_query($conn, "UPDATE site_user SET otp_lockout_until = '$lockoutStr' WHERE id = " . (int)$userRow['id']);
            \App\Services\OtpConfigService::logOtpDispatch($identity, 'customer', 'BLOCKED', ($isInputEmail ? 'email' : 'phone'), 'blocked', 4, "Exceeded 4 attempts limit; 30 min lockout applied");
            echo json_encode([
                "status" => "error",
                "message" => "Maximum OTP request limit reached (4 attempts). You must wait 30 minutes before requesting again."
            ]);
            exit;
        }
    }

    $newCount = $requestCount + 1;
    $lockoutSql = ($newCount >= 4) ? "'" . date('Y-m-d H:i:s', $now + 1800) . "'" : "NULL";
    $otp = (string)rand(100000, 999999);

    // --- DISPATCH EMAIL VIA FREE GMAIL SMTP / PHPMAILER ---
    $mailResult = null;
    if ($isInputEmail) {
        require_once __DIR__ . '/../app/helpers/MailHelper.php';
        $recipientName = $userRow['full_name'] ?? 'User';
        $mailResult = \App\Helpers\MailHelper::sendOtp($identity, $otp, $recipientName, 'customer');

        // If SMTP is configured and failed to send
        if (!$mailResult['success'] && empty($mailResult['unconfigured'])) {
            echo json_encode([
                "status" => "error",
                "message" => $mailResult['message'] ?? "Failed to deliver OTP email. Please try again."
            ]);
            exit;
        }
    }

    // Update or Insert into site_user
    if (!$userRow) {
        $emailVal = ($column === "email_address") ? "'$safeIdentity'" : "NULL";
        $phoneVal = ($column === "phone_number") ? "'$safeIdentity'" : "NULL";
        mysqli_query($conn, "
            INSERT INTO site_user (full_name, email_address, phone_number, otp, otp_created_at, otp_request_count, otp_lockout_until, otp_last_requested_at, password)
            VALUES ('', $emailVal, $phoneVal, '$otp', NOW(), $newCount, $lockoutSql, NOW(), '')
        ");
    } else {
        mysqli_query($conn, "
            UPDATE site_user 
            SET otp = '$otp',
                otp_created_at = NOW(),
                otp_request_count = $newCount,
                otp_lockout_until = $lockoutSql,
                otp_last_requested_at = NOW()
            WHERE id = " . (int)$userRow['id']
        );
    }

    // Response Handling
    if ($mailResult && !empty($mailResult['unconfigured'])) {
        // Dev fallback if SMTP credentials not filled in .env
        echo json_encode([
            "status" => "success",
            "message" => "OTP generated. (SMTP credentials not yet set in backend/.env; use debug code)",
            "otp" => $otp,
            "attempt" => $newCount,
            "max_attempts" => 4
        ]);
        exit;
    }

    echo json_encode([
        "status" => "success",
        "message" => "Verification code sent successfully to " . ($isInputEmail ? "your email." : "your phone.") . " (Attempt {$newCount} of 4)",
        "attempt" => $newCount,
        "max_attempts" => 4
    ]);
    exit;
}


/* ================= VERIFY OTP ================= */
if ($action == "verify_otp") {

    $identity = trim($_POST['identity'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    $safeIdentity = mysqli_real_escape_string($conn, $identity);
    $safeOtp = mysqli_real_escape_string($conn, $otp);

    if (isEmail($identity)) {
        $column = "email_address";
        $where_clause = "su.email_address = '$safeIdentity'";
    } else {
        $column = "phone_number";
        $clean_phone = substr(preg_replace('/[^0-9]/', '', $identity), -10);
        $where_clause = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(su.phone_number, ' ', ''), '+', ''), '-', ''), '(', ''), ')', '') LIKE '%$clean_phone'";
    }

    $query = mysqli_query($conn, "
        SELECT su.* FROM site_user su
        WHERE $where_clause
        AND su.otp='$safeOtp'
        AND su.otp_created_at >= (NOW() - INTERVAL 3 MINUTE)
    ");

    if (mysqli_num_rows($query) > 0) {

        $user = mysqli_fetch_assoc($query);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'] ?? '';
        $_SESSION['email'] = $user['email_address'] ?? '';
        $_SESSION['phone'] = (!empty($user['phone_number']) && strpos($user['phone_number'], '@') === false) ? $user['phone_number'] : '';

        // Synchronize with users and wallets tables for backend compatibility
        $email = mysqli_real_escape_string($conn, $user['email_address'] ?? '');
        $phone = mysqli_real_escape_string($conn, $user['phone_number'] ?? '');
        $fullName = mysqli_real_escape_string($conn, $user['full_name'] ?? 'User');
        $parts = explode(' ', $fullName, 2);
        $firstName = $parts[0];
        $lastName = $parts[1] ?? 'User';
        $pwHash = mysqli_real_escape_string($conn, $user['password'] ?? '');
        $userId = (int)$user['id'];

        try {
            $hasUsers = mysqli_query($conn, "SHOW TABLES LIKE 'users'");
            if ($hasUsers && mysqli_num_rows($hasUsers) > 0) {
                $checkUser = mysqli_query($conn, "SELECT id FROM users WHERE id = $userId");
                if ($checkUser && mysqli_num_rows($checkUser) == 0) {
                    mysqli_query($conn, "
                        INSERT INTO users (id, first_name, last_name, email, phone, password_hash, status)
                        VALUES ($userId, '$firstName', '$lastName', '$email', '$phone', '$pwHash', 'active')
                    ");
                    $hasRoles = mysqli_query($conn, "SHOW TABLES LIKE 'user_roles'");
                    if ($hasRoles && mysqli_num_rows($hasRoles) > 0) {
                        mysqli_query($conn, "INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ($userId, 3)"); // 3 = CUSTOMER
                    }
                }
            }

            $hasWallets = mysqli_query($conn, "SHOW TABLES LIKE 'wallets'");
            if ($hasWallets && mysqli_num_rows($hasWallets) > 0) {
                $checkWallet = mysqli_query($conn, "SELECT id FROM wallets WHERE user_id = $userId");
                if ($checkWallet && mysqli_num_rows($checkWallet) == 0) {
                    mysqli_query($conn, "INSERT INTO wallets (user_id, balance, currency) VALUES ($userId, 0.00, 'INR')");
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore optional synchronization errors if legacy tables don't exist
        }

        $stmtResetOtp = mysqli_prepare($conn, "
            UPDATE site_user
            SET otp = NULL,
                otp_created_at = NULL,
                otp_request_count = 0,
                otp_lockout_until = NULL,
                otp_last_requested_at = NULL
            WHERE id = ?
        ");
        if ($stmtResetOtp) {
            mysqli_stmt_bind_param($stmtResetOtp, "i", $userId);
            mysqli_stmt_execute($stmtResetOtp);
            mysqli_stmt_close($stmtResetOtp);
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::logAuth('login', $identity, 'success');
        }

        // Generate JWT Token using App\Services\AuthService
        $accessToken = '';
        $refreshToken = '';
        try {
            $authService = new \App\Services\AuthService();
            $tokens = $authService->generateTokens([
                'id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'role' => 'CUSTOMER',
                'avatar_url' => $user['avatar_url'] ?? null
            ]);
            $accessToken = $tokens['access_token'];
            $refreshToken = $tokens['refresh_token'];

            if (!empty($accessToken)) {
                $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
                setcookie("access_token", $accessToken, [
                    'expires' => time() + 3600,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $isSecure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
        } catch (\Exception $e) {
            // Silently handle error or log it
        }

        echo json_encode([
            "status" => "success",
            "access_token" => $accessToken,
            "refresh_token" => $refreshToken,
            "user" => [
                "id" => $userId,
                "first_name" => $firstName,
                "last_name" => $lastName,
                "email" => $email,
                "phone" => $phone,
                "role" => "CUSTOMER"
            ]
        ]);
    } else {
        if (class_exists('SystemLogger')) {
            SystemLogger::logAuth('login', $identity, 'unauthorized');
        }
        echo json_encode(["status" => "error", "message" => "Invalid or expired OTP"]);
    }

    exit;
}

/* ================= VERIFY JWT TOKEN (FOR SPLIT-DOMAIN WEB SESSION SYNC) ================= */
if ($action == "verify_token") {
    $token = trim($_POST['access_token'] ?? ($_GET['access_token'] ?? ''));
    if (empty($token)) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
            $token = trim($m[1]);
        }
    }

    if (empty($token)) {
        echo json_encode(["status" => "error", "message" => "Access token is required"]);
        exit;
    }

    try {
        $appConfig = require dirname(__DIR__) . '/config/app.php';
        $payload = \App\Helpers\JWT::decode($token, $appConfig['jwt']['secret']);
        $userId = (int)($payload['user_id'] ?? 0);

        if ($userId <= 0) {
            echo json_encode(["status" => "error", "message" => "Invalid customer token"]);
            exit;
        }

        $q = mysqli_query($conn, "SELECT id, full_name, email_address, phone_number FROM site_user WHERE id = $userId LIMIT 1");
        if (!$q || mysqli_num_rows($q) === 0) {
            echo json_encode(["status" => "error", "message" => "Customer account not found"]);
            exit;
        }

        $user = mysqli_fetch_assoc($q);
        $phone = (!empty($user['phone_number']) && strpos($user['phone_number'], '@') === false) ? $user['phone_number'] : '';

        echo json_encode([
            "status" => "success",
            "user" => [
                "id"        => (int)$user['id'],
                "full_name" => $user['full_name'] ?? '',
                "email"     => $user['email_address'] ?? '',
                "phone"     => $phone,
                "role"      => "CUSTOMER"
            ]
        ]);
    } catch (\Throwable $e) {
        echo json_encode(["status" => "error", "message" => "Token verification failed: " . $e->getMessage()]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Invalid request"]);

