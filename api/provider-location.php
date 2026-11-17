<?php
/**
 * ServEasy - Real-Time Service Provider Live Location API
 * 
 * Provides live GPS location streaming and real-time retrieval for Customer & Provider tracking:
 * 1. POST: Updates live coordinates (lat, lng, heading, speed, accuracy) from Provider device/app.
 * 2. GET:  Retrieves provider's live coordinates, distance to customer (Haversine), and ETA.
 * 3. Event Dispatch: Dispatches 'provider.location_update' to Customer via EventRepository.
 * 4. Multi-Probability compliant: supports GET/POST/PUT, JSON/form-data, action/ajax_action.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Helpers\DB;
use App\Helpers\Response;
use App\Helpers\Auth;
use App\Helpers\JWT;
use App\Repositories\EventRepository;

// CORS headers
if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/backend/api/')) {
    header('Content-Type: application/json; charset=UTF-8');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!empty($origin) && $origin !== '*') {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        header("Access-Control-Allow-Origin: {$scheme}://{$host}");
        header("Access-Control-Allow-Credentials: true");
    }
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Provider-Id, X-Customer-Id, Accept, Origin");

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

// ── 1. Request Input Normalization ───────────────────────────────────────────
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$rawInput = file_get_contents('php://input');
$jsonBody = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $jsonBody = $decoded;
    }
}

$request = array_merge($_GET, $_POST, $jsonBody);
$action = strtolower(trim($request['ajax_action'] ?? $request['action'] ?? ''));

// Haversine distance calculator in kilometers
function calculateDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadiusKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($earthRadiusKm * $c, 2);
}

// ── 2. Provider Identity Resolution ──────────────────────────────────────────
function resolveProviderId(array $request): ?int {
    // 1. Session Auth
    if (!empty($_SESSION['dealer']['id'])) {
        return (int)$_SESSION['dealer']['id'];
    }
    if (!empty($_SESSION['dealer_id'])) {
        return (int)$_SESSION['dealer_id'];
    }
    if (Auth::check() && Auth::hasRole('PROVIDER')) {
        return (int)Auth::id();
    }

    // 2. HTTP Header: X-Provider-Id
    $headerProviderId = $_SERVER['HTTP_X_PROVIDER_ID'] ?? null;
    if ($headerProviderId && is_numeric($headerProviderId)) {
        return (int)$headerProviderId;
    }

    // 3. JWT Bearer Token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $tokenPayload = JWT::verifyToken($matches[1]);
        if ($tokenPayload && !empty($tokenPayload['user_id'])) {
            return (int)$tokenPayload['user_id'];
        }
    }

    // 4. Parameter in Request
    if (!empty($request['provider_id']) && is_numeric($request['provider_id'])) {
        return (int)$request['provider_id'];
    }
    if (!empty($request['providerId']) && is_numeric($request['providerId'])) {
        return (int)$request['providerId'];
    }

    return null;
}

// ── 3. Handle POST / PUT (Location Ingestion) ─────────────────────────────────
if ($method === 'POST' || $method === 'PUT' || in_array($action, ['update', 'update_location', 'broadcast'])) {
    $lat = isset($request['latitude']) ? (float)$request['latitude'] : (isset($request['lat']) ? (float)$request['lat'] : null);
    $lng = isset($request['longitude']) ? (float)$request['longitude'] : (isset($request['lng']) ? (float)$request['lng'] : null);

    if ($lat === null || $lng === null) {
        Response::error("Valid latitude and longitude coordinates are required.", 422);
    }

    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
        Response::error("Coordinates are out of physical bounds.", 422);
    }

    $providerId = resolveProviderId($request);
    $bookingId = !empty($request['booking_id']) ? (int)$request['booking_id'] : (!empty($request['bookingId']) ? (int)$request['bookingId'] : null);

    // If providerId wasn't sent but bookingId is known, deduce providerId from the booking
    $db = DB::getConnection();

    if (!$providerId && $bookingId) {
        $bStmt = $db->prepare("SELECT provider_id FROM `vehicle_wash` WHERE id = ? LIMIT 1");
        $bStmt->execute([$bookingId]);
        $providerId = (int)$bStmt->fetchColumn() ?: null;
    }

    if (!$providerId) {
        Response::error("Provider identification required to update location.", 401);
    }

    $heading = isset($request['heading']) ? (float)$request['heading'] : 0.0;
    $speed = isset($request['speed']) ? (float)$request['speed'] : 0.0;
    $accuracy = isset($request['accuracy']) ? (float)$request['accuracy'] : 0.0;
    $locStatus = trim($request['status'] ?? 'in_progress');

    try {
        // 1. Update Provider's record in canonical table serveasy-providers
        $pStmt = $db->prepare("
            UPDATE `serveasy-providers`
            SET `latitude` = :lat, `longitude` = :lng, `heading` = :heading, `speed` = :speed, `accuracy` = :accuracy, `updated_at` = NOW()
            WHERE `id` = :pid
        ");
        $pStmt->execute([
            ':lat' => $lat,
            ':lng' => $lng,
            ':heading' => $heading,
            ':speed' => $speed,
            ':accuracy' => $accuracy,
            ':pid' => $providerId
        ]);

        // 2. Broadcast real-time SSE event to customer if booking is known
        if ($bookingId) {
            try {
                $cStmt = $db->prepare("SELECT customer_id, booking_number FROM `vehicle_wash` WHERE id = ? LIMIT 1");
                $cStmt->execute([$bookingId]);
                $bookingRow = $cStmt->fetch();

                if ($bookingRow && !empty($bookingRow['customer_id'])) {
                    $eventRepo = new EventRepository();
                    $eventRepo->recordEvent('provider.location_update', $bookingId, 'CUSTOMER', (int)$bookingRow['customer_id'], [
                        'booking_id' => $bookingId,
                        'booking_number' => $bookingRow['booking_number'],
                        'provider_id' => $providerId,
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'heading' => $heading,
                        'speed' => $speed,
                        'accuracy' => $accuracy,
                        'status' => $locStatus,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
            } catch (\Throwable $eEvt) {
                error_log("Failed to dispatch provider.location_update event: " . $eEvt->getMessage());
            }
        }

        Response::success([
            'provider_id' => $providerId,
            'booking_id' => $bookingId,
            'latitude' => $lat,
            'longitude' => $lng,
            'heading' => $heading,
            'speed' => $speed,
            'accuracy' => $accuracy,
            'status' => $locStatus,
            'updated_at' => date('Y-m-d H:i:s')
        ], "Provider live location updated successfully.");
    } catch (\Throwable $e) {
        Response::error("Database error while updating location: " . $e->getMessage(), 500);
    }
}

// ── 4. Handle GET (Location Retrieval / Tracking) ─────────────────────────────
$bookingId = !empty($request['booking_id']) ? (int)$request['booking_id'] : (!empty($request['bookingId']) ? (int)$request['bookingId'] : null);
$providerId = !empty($request['provider_id']) ? (int)$request['provider_id'] : (!empty($request['providerId']) ? (int)$request['providerId'] : null);

$db = DB::getConnection();

if ($bookingId) {
    try {
        // Query vehicle_wash for booking details and join canonical serveasy-providers for coordinates
        $bStmt = $db->prepare("
            SELECT vw.id, vw.booking_number, vw.customer_id, vw.provider_id, vw.status,
                   vw.address, vw.landmark, vw.pincode, vw.location_type,
                   ST_X(vw.location) AS booking_lat, ST_Y(vw.location) AS booking_lng,
                   p.full_name AS provider_name, p.phone AS provider_phone,
                   p.location_address AS provider_address,
                   p.latitude AS provider_lat, p.longitude AS provider_lng,
                   p.heading AS provider_heading, p.speed AS provider_speed, p.accuracy AS provider_accuracy,
                   p.updated_at AS provider_updated_at
            FROM `vehicle_wash` vw
            LEFT JOIN `serveasy-providers` p ON p.id = vw.provider_id
            WHERE vw.id = :bid
            LIMIT 1
        ");
        $bStmt->execute([':bid' => $bookingId]);
        $booking = $bStmt->fetch();

        if (!$booking) {
            Response::error("Booking not found.", 404);
        }

        $assignedProviderId = (int)$booking['provider_id'];
        if (!$assignedProviderId) {
            Response::success([
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'],
                'booking_status' => $booking['status'],
                'provider_assigned' => false,
                'message' => 'No technician assigned to this booking yet.'
            ]);
        }

        $lat = !empty($booking['provider_lat']) ? (float)$booking['provider_lat'] : null;
        $lng = !empty($booking['provider_lng']) ? (float)$booking['provider_lng'] : null;
        $heading = (float)($booking['provider_heading'] ?? 0.0);
        $speed = (float)($booking['provider_speed'] ?? 0.0);
        $accuracy = (float)($booking['provider_accuracy'] ?? 0.0);
        $updatedAt = $booking['provider_updated_at'];
        $statusStr = $lat !== null ? 'in_progress' : 'assigned';

        // Resolve customer destination coordinates from booking location or user_addresses
        $customerLat = null;
        $customerLng = null;
        $bLat = (float)($booking['booking_lat'] ?? 0);
        $bLng = (float)($booking['booking_lng'] ?? 0);
        if ($bLat != 0.0 && $bLng != 0.0) {
            $customerLat = $bLat;
            $customerLng = $bLng;
        } else if (!empty($booking['customer_id'])) {
            $addrStmt = $db->prepare("
                SELECT latitude, longitude FROM `user_addresses`
                WHERE user_id = :cid AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0
                ORDER BY is_default DESC, updated_at DESC
                LIMIT 1
            ");
            $addrStmt->execute([':cid' => (int)$booking['customer_id']]);
            $cAddr = $addrStmt->fetch();
            if ($cAddr && !empty($cAddr['latitude'])) {
                $customerLat = (float)$cAddr['latitude'];
                $customerLng = (float)$cAddr['longitude'];
            }
        }

        $distanceKm = null;
        $etaMinutes = null;
        if ($lat !== null && $lng !== null && $customerLat !== null && $customerLng !== null) {
            $distanceKm = calculateDistanceKm($lat, $lng, $customerLat, $customerLng);
            // Average urban vehicle speed ~25 km/h
            $etaMinutes = max(1, (int)round(($distanceKm / 25.0) * 60));
        }

        $secondsAgo = $updatedAt ? max(0, time() - strtotime($updatedAt)) : null;
        $isLive = ($secondsAgo !== null && $secondsAgo <= 300); // Live if updated within 5 mins

        Response::success([
            'booking_id' => $bookingId,
            'booking_number' => $booking['booking_number'],
            'booking_status' => $booking['status'],
            'location_type' => $booking['location_type'],
            'provider_assigned' => true,
            'provider_id' => $assignedProviderId,
            'provider_name' => $booking['provider_name'],
            'provider_phone' => $booking['provider_phone'],
            'latitude' => $lat,
            'longitude' => $lng,
            'heading' => $heading,
            'speed' => $speed,
            'accuracy' => $accuracy,
            'tracking_status' => $statusStr,
            'updated_at' => $updatedAt,
            'seconds_ago' => $secondsAgo,
            'is_live' => $isLive,
            'customer_lat' => $customerLat,
            'customer_lng' => $customerLng,
            'distance_km' => $distanceKm,
            'eta_minutes' => $etaMinutes,
            'customer_address' => $booking['address'],
            'customer_landmark' => $booking['landmark'],
            'customer_pincode' => $booking['pincode']
        ], "Tracking details fetched successfully.");
    } catch (\Throwable $e) {
        Response::error("Error retrieving booking tracking location: " . $e->getMessage(), 500);
    }
}

if ($providerId) {
    try {
        $pStmt = $db->prepare("
            SELECT id, full_name, email, phone, location_address, latitude, longitude,
                   service_range_km, is_active, verified_status, updated_at
            FROM `serveasy-providers`
            WHERE id = :pid
            LIMIT 1
        ");
        $pStmt->execute([':pid' => $providerId]);
        $provider = $pStmt->fetch();

        if (!$provider) {
            Response::error("Provider not found.", 404);
        }

        $lat = !empty($provider['latitude']) ? (float)$provider['latitude'] : null;
        $lng = !empty($provider['longitude']) ? (float)$provider['longitude'] : null;
        $updatedAt = $provider['updated_at'];
        $secondsAgo = $updatedAt ? max(0, time() - strtotime($updatedAt)) : null;

        Response::success([
            'provider_id' => (int)$provider['id'],
            'full_name' => $provider['full_name'],
            'latitude' => $lat,
            'longitude' => $lng,
            'location_address' => $provider['location_address'],
            'updated_at' => $updatedAt,
            'seconds_ago' => $secondsAgo,
            'is_live' => ($secondsAgo !== null && $secondsAgo <= 300),
            'is_active' => (bool)$provider['is_active'],
            'verified_status' => $provider['verified_status']
        ], "Provider location retrieved successfully.");
    } catch (\Throwable $e) {
        Response::error("Error retrieving provider location: " . $e->getMessage(), 500);
    }
}

Response::error("Either booking_id or provider_id is required.", 422);

