<?php
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\DB;
use App\Helpers\Response;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Parse input
$input = [];
$raw = file_get_contents('php://input');
if (!empty($raw)) {
    $input = json_decode($raw, true) ?? [];
}
$request = array_merge($_GET, $_POST, $input);

$userId = null;
if (isset($request['user_id'])) {
    $userId = (int)$request['user_id'];
} elseif (isset($request['customer_id'])) {
    $userId = (int)$request['customer_id'];
} elseif (Auth::id()) {
    $userId = Auth::id();
}

if ($method === 'GET') {
    if (!$userId) {
        Response::error("User ID is required to fetch addresses", 422);
    }

    try {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT id, user_id, label, address, landmark, pincode, latitude, longitude, is_default
            FROM `user_addresses`
            WHERE user_id = :uid
            ORDER BY is_default DESC, updated_at DESC
        ");
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll();

        // Deduplicate addresses by address + pincode
        $seen = [];
        $addresses = [];
        foreach ($rows as $r) {
            $key = strtolower(trim($r['address'])) . '|' . trim($r['pincode']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $addresses[] = [
                    'id' => (int)$r['id'],
                    'label' => $r['label'] ?: 'Home',
                    'address' => $r['address'],
                    'landmark' => $r['landmark'] ?: '',
                    'pincode' => $r['pincode'],
                    'latitude' => $r['latitude'] ? (float)$r['latitude'] : null,
                    'longitude' => $r['longitude'] ? (float)$r['longitude'] : null,
                    'isDefault' => (bool)$r['is_default']
                ];
            }
        }

        Response::success($addresses, "User addresses retrieved successfully");
    } catch (\Throwable $e) {
        Response::error($e->getMessage(), 500);
    }
} elseif ($method === 'POST') {
    if (!$userId) {
        Response::error("User ID is required to save address", 422);
    }

    $address = trim($request['address'] ?? '');
    $landmark = trim($request['landmark'] ?? '');
    $pincode = trim($request['pincode'] ?? '');
    $label = trim($request['label'] ?? 'Home');
    $lat = !empty($request['latitude']) ? (float)$request['latitude'] : null;
    $lng = !empty($request['longitude']) ? (float)$request['longitude'] : null;

    if (empty($address)) {
        Response::error("Address field is required", 422);
    }

    try {
        $db = DB::getConnection();

        // Check for existing identical address
        $checkStmt = $db->prepare("
            SELECT id, user_id, label, address, landmark, pincode, latitude, longitude, is_default
            FROM `user_addresses`
            WHERE user_id = :uid 
              AND LOWER(TRIM(address)) = LOWER(TRIM(:address))
              AND pincode = :pincode
            LIMIT 1
        ");
        $checkStmt->execute([
            'uid' => $userId,
            'address' => $address,
            'pincode' => $pincode
        ]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            if ($lat !== null && $lng !== null) {
                $upStmt = $db->prepare("
                    UPDATE `user_addresses`
                    SET landmark = :landmark, latitude = :lat, longitude = :lng, updated_at = NOW()
                    WHERE id = :id
                ");
                $upStmt->execute([
                    'landmark' => $landmark,
                    'lat' => $lat,
                    'lng' => $lng,
                    'id' => $existing['id']
                ]);
                $existing['landmark'] = $landmark;
                $existing['latitude'] = $lat;
                $existing['longitude'] = $lng;
            }
            Response::success([
                'id' => (int)$existing['id'],
                'label' => $existing['label'] ?: $label,
                'address' => $existing['address'],
                'landmark' => $existing['landmark'] ?: '',
                'pincode' => $existing['pincode'],
                'latitude' => $existing['latitude'] ? (float)$existing['latitude'] : null,
                'longitude' => $existing['longitude'] ? (float)$existing['longitude'] : null,
                'isDefault' => (bool)$existing['is_default']
            ], "Address already saved");
        }

        $stmt = $db->prepare("
            INSERT INTO `user_addresses`
                (user_id, label, address, landmark, pincode, latitude, longitude, is_default)
            VALUES
                (:uid, :label, :address, :landmark, :pincode, :lat, :lng, 0)
        ");
        $stmt->execute([
            'uid' => $userId,
            'label' => $label,
            'address' => $address,
            'landmark' => $landmark,
            'pincode' => $pincode,
            'lat' => $lat,
            'lng' => $lng
        ]);

        $newId = (int)$db->lastInsertId();

        Response::success([
            'id' => $newId,
            'label' => $label,
            'address' => $address,
            'landmark' => $landmark,
            'pincode' => $pincode,
            'latitude' => $lat,
            'longitude' => $lng,
            'isDefault' => false
        ], "Address saved successfully", 201);
    } catch (\Throwable $e) {
        Response::error($e->getMessage(), 500);
    }
} else {
    Response::error("Method not allowed", 405);
}
