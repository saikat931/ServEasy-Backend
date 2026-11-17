<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/services/OtpConfigService.php';
require_once __DIR__ . '/../app/services/DomainConfigService.php';

use App\Repositories\SettingRepository;
use App\Services\OtpConfigService;
use App\Services\DomainConfigService;
use App\Helpers\Response;

$repo = new SettingRepository();
$action = $_REQUEST['action'] ?? 'get_convenience_fee';
$country = $_REQUEST['country'] ?? 'IN';

if ($action === 'get_convenience_fee' || $action === 'get_fee') {
    $fee = $repo->getConvenienceFee($country);
    Response::success($fee, "Platform convenience fee retrieved");
} elseif ($action === 'get_otp_config' || $action === 'get_otp_settings') {
    $config = OtpConfigService::getConfig();
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'OTP login configuration retrieved successfully',
        'data' => $config
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} elseif ($action === 'update_otp_config') {
    // Session check for Super Admin
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $isSuperAdmin = false;
    if (isset($_SESSION['superadmin'])) {
        $isSuperAdmin = true;
    } elseif (isset($_SESSION['standard_admin'])) {
        $role = strtolower($_SESSION['standard_admin']['role'] ?? '');
        $isSuperAdmin = in_array($role, ['super_admin', 'superadmin', 'master_admin']);
    }

    if (!$isSuperAdmin) {
        Response::error("Access denied: Only Super Administrators can modify global OTP settings.", 403);
    }

    $emailEnabled = filter_var($_POST['email_otp_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $phoneEnabled = filter_var($_POST['phone_otp_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $customLabel = isset($_POST['custom_label']) ? trim($_POST['custom_label']) : null;
    $customPlaceholder = isset($_POST['custom_placeholder']) ? trim($_POST['custom_placeholder']) : null;

    // If both disabled, throw error
    if (!$emailEnabled && !$phoneEnabled) {
        Response::error("At least one OTP login method (Email or Phone Number) must remain enabled.", 422);
    }

    $updatedBy = $_SESSION['standard_admin']['username'] ?? $_SESSION['superadmin']['username'] ?? 'Super Admin';

    try {
        $updated = OtpConfigService::updateConfig($emailEnabled, $phoneEnabled, $customLabel, $customPlaceholder, $updatedBy);
        Response::success($updated, "OTP login configuration updated successfully");
    } catch (\Throwable $e) {
        Response::error($e->getMessage(), 500);
    }
} elseif ($action === 'get_domain_config' || $action === 'get_app_config') {
    $config = DomainConfigService::getConfig();
    $endpoints = DomainConfigService::getAllEndpoints();
    Response::success([
        'config' => $config,
        'endpoints' => $endpoints
    ], "Live domain and endpoint directory retrieved successfully");
} elseif ($action === 'update_domain_config') {
    // Session check for Super Admin
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $isSuperAdmin = false;
    if (isset($_SESSION['superadmin'])) {
        $isSuperAdmin = true;
    } elseif (isset($_SESSION['standard_admin'])) {
        $role = strtolower($_SESSION['standard_admin']['role'] ?? '');
        $isSuperAdmin = in_array($role, ['super_admin', 'superadmin', 'master_admin']);
    }

    if (!$isSuperAdmin) {
        Response::error("Access denied: Only Super Administrators can modify live domain configuration.", 403);
    }

    $updatedBy = $_SESSION['standard_admin']['username'] ?? $_SESSION['superadmin']['username'] ?? 'Super Admin';

    try {
        $updated = DomainConfigService::updateConfig($_POST, $updatedBy);
        Response::success($updated, "Live domain configuration updated and synchronized successfully");
    } catch (\Throwable $e) {
        Response::error($e->getMessage(), 400);
    }
} elseif ($action === 'ping_domain') {
    $targetUrl = trim($_REQUEST['target_url'] ?? '');
    if (empty($targetUrl)) {
        Response::error("Target URL is required for testing connection.", 400);
    }

    $startTime = microtime(true);
    $ch = curl_init($targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ServEasy-Diagnostic-Ping/1.0');
    curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = round((microtime(true) - $startTime) * 1000, 2);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error && $httpCode === 0) {
        Response::error("Ping failed: {$error}", 502, [
            'http_code' => 0,
            'latency_ms' => $totalTime,
            'is_reachable' => false
        ]);
    }

    Response::success([
        'target_url' => $targetUrl,
        'http_code' => $httpCode,
        'latency_ms' => $totalTime,
        'is_reachable' => ($httpCode >= 200 && $httpCode < 500)
    ], "Domain connection test completed (HTTP {$httpCode}, {$totalTime}ms)");
} else {
    Response::error("Invalid action requested", 400);
}
