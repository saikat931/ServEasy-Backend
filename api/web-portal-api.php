<?php
/**
 * ServEasy Central Customer Web Portal API
 * Serves all multi-domain Customer Web pages (Vehicle Wash Wizard, User Profile, Blog, Maids)
 * directly from the Central Backend database so standalone Web domains need ZERO MySQL credentials.
 */
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$req = is_array($jsonBody) ? array_merge($_REQUEST, $jsonBody) : $_REQUEST;
$action = trim((string)($req['action'] ?? $req['ajax_action'] ?? ''));

// Helper: authenticate customer via JWT (Authorization: Bearer, X-Auth-Token, or session)
function resolveCustomerId(array $req): int
{
    $token = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
        $token = trim($m[1]);
    }
    if (empty($token) && !empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        $token = trim($_SERVER['HTTP_X_AUTH_TOKEN']);
    }
    if (empty($token) && !empty($req['access_token'])) {
        $token = trim((string)$req['access_token']);
    }

    if (!empty($token)) {
        try {
            $appConfig = require dirname(__DIR__) . '/config/app.php';
            $payload = \App\Helpers\JWT::decode($token, $appConfig['jwt']['secret']);
            if (!empty($payload['user_id'])) {
                return (int)$payload['user_id'];
            }
        } catch (\Throwable $e) {}
    }

    if (!empty($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    return 0;
}

try {
    $db = \App\Helpers\DB::getConnection();

    // =========================================================================
    // 1. SAVE USER ADDRESS (Vehicle Car & Bike Wash Wizard)
    // =========================================================================
    if ($action === 'save_user_address') {
        $uid = resolveCustomerId($req);
        if ($uid <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'User authentication required']);
            exit;
        }

        $address  = trim((string)($req['address'] ?? ''));
        $landmark = trim((string)($req['landmark'] ?? ''));
        $pincode  = trim((string)($req['pincode'] ?? ''));
        $lat      = !empty($req['latitude']) ? (float)$req['latitude'] : (!empty($req['lat']) ? (float)$req['lat'] : null);
        $lng      = !empty($req['longitude']) ? (float)$req['longitude'] : (!empty($req['lng']) ? (float)$req['lng'] : null);
        $label    = trim((string)($req['label'] ?? 'Home')) ?: 'Home';

        if ($address === '') {
            echo json_encode(['status' => 'skip', 'message' => 'Empty address — nothing saved']);
            exit;
        }

        $checkStmt = $db->prepare("
            SELECT id, label, address, landmark, pincode, latitude, longitude
            FROM `user_addresses`
            WHERE user_id = :uid
              AND LOWER(TRIM(address)) = LOWER(TRIM(:address))
              AND pincode = :pincode
            LIMIT 1
        ");
        $checkStmt->execute([':uid' => $uid, ':address' => $address, ':pincode' => $pincode]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($lat !== null && $lng !== null) {
                $db->prepare("
                    UPDATE `user_addresses`
                    SET landmark = :landmark, latitude = :lat, longitude = :lng, updated_at = NOW()
                    WHERE id = :id
                ")->execute([
                    ':landmark' => $landmark,
                    ':lat'      => $lat,
                    ':lng'      => $lng,
                    ':id'       => $existing['id']
                ]);
                $existing['landmark']  = $landmark;
                $existing['latitude']  = $lat;
                $existing['longitude'] = $lng;
            }
            echo json_encode([
                'status'         => 'ok',
                'already_exists' => true,
                'id'             => (int)$existing['id'],
                'address'        => [
                    'id'        => (int)$existing['id'],
                    'label'     => $existing['label'],
                    'address'   => $existing['address'],
                    'landmark'  => $existing['landmark'],
                    'pincode'   => $existing['pincode'],
                    'latitude'  => $existing['latitude'],
                    'longitude' => $existing['longitude']
                ]
            ]);
            exit;
        }

        $cntStmt = $db->prepare("SELECT COUNT(*) FROM `user_addresses` WHERE user_id = ?");
        $cntStmt->execute([$uid]);
        $isDefault = ((int)$cntStmt->fetchColumn() === 0) ? 1 : 0;

        $db->prepare("
            INSERT INTO `user_addresses`
                (user_id, label, address, landmark, pincode, latitude, longitude, is_default)
            VALUES
                (:uid, :label, :address, :landmark, :pincode, :lat, :lng, :is_default)
        ")->execute([
            ':uid'        => $uid,
            ':label'      => $label,
            ':address'    => $address,
            ':landmark'   => $landmark,
            ':pincode'    => $pincode,
            ':lat'        => $lat,
            ':lng'        => $lng,
            ':is_default' => $isDefault,
        ]);

        $newId = (int)$db->lastInsertId();
        echo json_encode([
            'status'         => 'ok',
            'already_exists' => false,
            'id'             => $newId,
            'address'        => [
                'id'        => $newId,
                'label'     => $label,
                'address'   => $address,
                'landmark'  => $landmark,
                'pincode'   => $pincode,
                'latitude'  => $lat,
                'longitude' => $lng
            ]
        ]);
        exit;
    }

    // =========================================================================
    // 2. VEHICLE WASH WIZARD INIT
    // =========================================================================
    if ($action === 'vehicle_wash_init') {
        $uid = resolveCustomerId($req);

        // Convenience fee
        $fee = [
            'fee_amount'          => 20.00,
            'fee_type'            => 'fixed',
            'is_active'           => 1,
            'service_scope'       => 'all',
            'applicable_services' => []
        ];
        try {
            $feeRow = $db->query("SELECT fee_amount, fee_type, is_active, service_scope, applicable_services FROM `platform_settings` WHERE setting_key = 'convenience_fee' AND country_code = 'IN' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($feeRow) {
                $fee['fee_amount'] = (float)($feeRow['fee_amount'] ?? 20.00);
                $fee['fee_type'] = ($feeRow['fee_type'] === 'percentage') ? 'percentage' : 'fixed';
                $fee['is_active'] = !empty($feeRow['is_active']) ? 1 : 0;
                $fee['service_scope'] = $feeRow['service_scope'] ?? 'all';
                if (!empty($feeRow['applicable_services'])) {
                    $dec = json_decode($feeRow['applicable_services'], true);
                    $fee['applicable_services'] = is_array($dec) ? $dec : [];
                }
            }
        } catch (\Throwable $t) {}

        $currentUser = [];
        $userAddresses = [];
        $profile = ['full_name' => '', 'email_address' => '', 'phone_number' => ''];

        if ($uid > 0) {
            try {
                $st = $db->prepare("SELECT address, landmark, pincode, latitude, longitude FROM `user_addresses` WHERE user_id = ? AND is_default = 1 ORDER BY updated_at DESC LIMIT 1");
                $st->execute([$uid]);
                $currentUser = $st->fetch(PDO::FETCH_ASSOC) ?: [];

                $stAll = $db->prepare("SELECT id, label, address, landmark, pincode, latitude, longitude, is_default FROM `user_addresses` WHERE user_id = ? ORDER BY is_default DESC, updated_at DESC");
                $stAll->execute([$uid]);
                $rawAddresses = $stAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $seenKeys = [];
                foreach ($rawAddresses as $addr) {
                    $key = strtolower(trim($addr['address'] ?? '')) . '|' . trim($addr['pincode'] ?? '');
                    if (!isset($seenKeys[$key])) {
                        $seenKeys[$key] = true;
                        $userAddresses[] = $addr;
                    }
                }

                $stProf = $db->prepare("SELECT full_name, email_address, phone_number FROM `site_user` WHERE id = ? LIMIT 1");
                $stProf->execute([$uid]);
                $profile = $stProf->fetch(PDO::FETCH_ASSOC) ?: $profile;
            } catch (\Throwable $t) {}
        }

        $allSubcategories = $db->query("
            SELECT sc.id, sc.name, sc.slug, c.id as cat_id, c.name as category_name
            FROM `serveasy-subcategories` sc
            JOIN `serveasy-categories` c ON sc.category_id = c.id
            WHERE sc.is_active = 1
            ORDER BY sc.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $allServices = $db->query("
            SELECT s.*,
                   COALESCE(c.company_name, sp.full_name) AS business_name,
                   4.5 AS provider_rating,
                   sp.is_active AS provider_available,
                   sc.name AS subcategory_name,
                   sc.slug AS subcategory_slug,
                   COALESCE(c.company_name, sp.full_name) AS provider_company,
                   sp.location_address AS provider_address,
                   s.service_range_km
            FROM `serveasy-services` s
            JOIN `serveasy-providers` sp ON s.provider_id = sp.id
            LEFT JOIN `serveasy-company` c ON c.provider_id = sp.id
            JOIN `serveasy-subcategories` sc ON s.subcategory_id = sc.id
            WHERE s.is_active = 1
              AND s.approval_status = 'approved'
              AND sp.verified_status = 'approved'
              AND s.subcategory_id IN (1, 2, 3, 6, 7, 8)
            ORDER BY s.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($allServices as &$s) {
            $stPkg = $db->prepare("SELECT id, name, description, price, features FROM `serveasy-service_packages` WHERE service_id = :id ORDER BY price ASC");
            $stPkg->execute(['id' => $s['id']]);
            $s['packages'] = $stPkg->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stAdd = $db->prepare("SELECT id, name, price FROM `serveasy-service_addons` WHERE service_id = :id ORDER BY id ASC");
            $stAdd->execute(['id' => $s['id']]);
            $s['addons'] = $stAdd->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stImg = $db->prepare("SELECT image_url FROM `serveasy-service_images` WHERE service_id = :id AND is_primary = 1 LIMIT 1");
            $stImg->execute(['id' => $s['id']]);
            $imgRow = $stImg->fetch(PDO::FETCH_ASSOC);
            $s['primary_image'] = $imgRow ? $imgRow['image_url'] : null;
        }
        unset($s);

        $providers = $db->query("
            SELECT sp.id, COALESCE(c.company_name, sp.full_name) AS business_name, 4.5 AS rating, sp.is_active AS is_available,
                   COALESCE(c.company_name, sp.full_name) AS company_name, c.id AS company_id,
                   (CASE WHEN c.id IS NOT NULL AND c.company_name IS NOT NULL AND TRIM(c.company_name) != '' THEN 0 ELSE 1 END) AS is_single_provider,
                   sp.location_address, MAX(s.service_range_km) AS service_range_km,
                   sp.full_name, sp.phone AS phone_number
            FROM `serveasy-providers` sp
            LEFT JOIN `serveasy-company` c ON c.provider_id = sp.id
            JOIN `serveasy-services` s ON s.provider_id = sp.id
            WHERE s.is_active = 1
              AND s.approval_status = 'approved'
              AND sp.verified_status = 'approved'
              AND s.subcategory_id IN (1, 2, 3, 6, 7, 8)
            GROUP BY sp.id
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $providerBranches = $db->query("
            SELECT b.id, b.branch_name, b.address, b.phone,
                   COALESCE(c.company_name, sp.full_name) AS business_name,
                   sp.id AS provider_sp_id,
                   sp.id AS provider_id
            FROM `serveasy-provider-branches` b
            JOIN `serveasy-providers` sp ON b.provider_id = sp.id
            LEFT JOIN `serveasy-company` c ON c.provider_id = sp.id
            JOIN `serveasy-services` s ON s.provider_id = sp.id
            WHERE b.is_active = 1
              AND s.is_active = 1
              AND s.subcategory_id IN (1, 2, 3, 6, 7, 8)
            GROUP BY b.id
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $preselectedBooking = null;
        if (!empty($req['booking_id']) && $uid > 0) {
            $stB = $db->prepare("
                SELECT b.*, s.name as service_name, COALESCE(c.company_name, sp.full_name) as provider_name
                FROM vehicle_wash b
                JOIN `serveasy-services` s ON b.service_id = s.id
                JOIN `serveasy-providers` sp ON b.provider_id = sp.id
                LEFT JOIN `serveasy-company` c ON c.provider_id = sp.id
                WHERE b.id = :id AND b.customer_id = :cust_id
            ");
            $stB->execute(['id' => (int)$req['booking_id'], 'cust_id' => $uid]);
            $preselectedBooking = $stB->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        echo json_encode([
            'status'             => 'success',
            'fee'                => $fee,
            'currentUser'        => $currentUser,
            'userAddresses'      => $userAddresses,
            'profile'            => $profile,
            'allSubcategories'   => $allSubcategories,
            'allServices'        => $allServices,
            'providers'          => $providers,
            'providerBranches'   => $providerBranches,
            'preselectedBooking' => $preselectedBooking,
        ]);
        exit;
    }

    // =========================================================================
    // 3. USER PROFILE INIT & AJAX ACTIONS
    // =========================================================================
    if ($action === 'user_profile_init') {
        $uid = resolveCustomerId($req);
        if ($uid <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
            exit;
        }

        $stUser = $db->prepare("SELECT id, full_name, email_address, phone_number, is_active, created_at, updated_at FROM `site_user` WHERE id = ? LIMIT 1");
        $stUser->execute([$uid]);
        $user = $stUser->fetch(PDO::FETCH_ASSOC) ?: null;

        $stKyc = $db->prepare("SELECT verification_id, id_type, id_number, verification_status, created_at, verified_at FROM `id_verifications` WHERE user_id = ? ORDER BY verification_id DESC LIMIT 1");
        $stKyc->execute([$uid]);
        $kyc = $stKyc->fetch(PDO::FETCH_ASSOC) ?: null;

        $stStats = $db->prepare("
            SELECT COUNT(*) as total_bookings,
                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
                   SUM(CASE WHEN status IN ('pending', 'accepted', 'in_progress') THEN 1 ELSE 0 END) as active_bookings,
                   COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as total_spent
            FROM `vehicle_wash` WHERE customer_id = ?
        ");
        $stStats->execute([$uid]);
        $stats = $stStats->fetch(PDO::FETCH_ASSOC) ?: [];

        $stAddr = $db->prepare("SELECT * FROM `user_addresses` WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
        $stAddr->execute([$uid]);
        $rawAddresses = $stAddr->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $seen = [];
        $addresses = [];
        foreach ($rawAddresses as $addr) {
            $k = strtolower(trim($addr['address'] ?? '')) . '|' . trim($addr['pincode'] ?? '');
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $addresses[] = $addr;
            }
        }

        $stRecent = $db->prepare("
            SELECT id, booking_number, vehicle_type, vehicle_brand, vehicle_model, vehicle_reg_no, schedule_date, schedule_time, total_amount, status, payment_status, payment_method, created_at
            FROM `vehicle_wash` WHERE customer_id = ? ORDER BY id DESC LIMIT 4
        ");
        $stRecent->execute([$uid]);
        $recentBookings = $stRecent->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'status'         => 'success',
            'user'           => $user,
            'kyc'            => $kyc,
            'stats'          => $stats,
            'addresses'      => $addresses,
            'recentBookings' => $recentBookings,
        ]);
        exit;
    }

    if (in_array($action, ['update_personal_info', 'profile_save_address', 'profile_delete_address', 'profile_set_default'], true)) {
        $uid = resolveCustomerId($req);
        if ($uid <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
            exit;
        }

        if ($action === 'update_personal_info') {
            $fullName = trim((string)($req['full_name'] ?? ''));
            $email = trim((string)($req['email_address'] ?? ''));
            $phone = trim((string)($req['phone_number'] ?? ''));

            if ($fullName === '') {
                echo json_encode(['status' => 'error', 'message' => 'Full Name cannot be empty']);
                exit;
            }
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address']);
                exit;
            }
            if (!empty($email)) {
                $chk = $db->prepare("SELECT id FROM `site_user` WHERE email_address = :email AND id != :uid");
                $chk->execute([':email' => $email, ':uid' => $uid]);
                if ($chk->fetch()) {
                    echo json_encode(['status' => 'error', 'message' => 'This email address is already in use by another account']);
                    exit;
                }
            }
            $db->prepare("UPDATE `site_user` SET full_name = :name, email_address = :email, phone_number = :phone, updated_at = NOW() WHERE id = :uid")
               ->execute([':name' => $fullName, ':email' => !empty($email) ? $email : null, ':phone' => !empty($phone) ? $phone : null, ':uid' => $uid]);
            echo json_encode(['status' => 'ok', 'message' => 'Your profile information has been successfully updated!']);
            exit;
        }

        if ($action === 'profile_save_address') {
            $addr_id  = !empty($req['addr_id']) ? (int)$req['addr_id'] : 0;
            $address  = trim((string)($req['address'] ?? ''));
            $landmark = trim((string)($req['landmark'] ?? ''));
            $pincode  = trim((string)($req['pincode'] ?? ''));
            $label    = trim((string)($req['label'] ?? 'Home')) ?: 'Home';
            $lat      = !empty($req['latitude']) ? (float)$req['latitude'] : null;
            $lng      = !empty($req['longitude']) ? (float)$req['longitude'] : null;

            if ($address === '' || $pincode === '') {
                echo json_encode(['status' => 'error', 'message' => 'Full address and Pincode are required']);
                exit;
            }

            if ($addr_id > 0) {
                $db->prepare("UPDATE `user_addresses` SET label=:label, address=:address, landmark=:landmark, pincode=:pincode, latitude=:lat, longitude=:lng, updated_at=NOW() WHERE id=:id AND user_id=:uid")
                   ->execute([':label' => $label, ':address' => $address, ':landmark' => $landmark, ':pincode' => $pincode, ':lat' => $lat, ':lng' => $lng, ':id' => $addr_id, ':uid' => $uid]);
            } else {
                $chk = $db->prepare("SELECT COUNT(*) FROM `user_addresses` WHERE user_id = ?");
                $chk->execute([$uid]);
                $is_default = ((int)$chk->fetchColumn() === 0) ? 1 : 0;
                $db->prepare("INSERT INTO `user_addresses` (user_id, label, address, landmark, pincode, latitude, longitude, is_default, created_at, updated_at) VALUES (:uid, :label, :address, :landmark, :pincode, :lat, :lng, :is_default, NOW(), NOW())")
                   ->execute([':uid' => $uid, ':label' => $label, ':address' => $address, ':landmark' => $landmark, ':pincode' => $pincode, ':lat' => $lat, ':lng' => $lng, ':is_default' => $is_default]);
            }
            echo json_encode(['status' => 'ok', 'message' => 'Address successfully saved!']);
            exit;
        }

        if ($action === 'profile_delete_address') {
            $addr_id = (int)($req['addr_id'] ?? 0);
            $db->prepare("DELETE FROM `user_addresses` WHERE id = ? AND user_id = ?")->execute([$addr_id, $uid]);
            $chk = $db->prepare("SELECT id FROM `user_addresses` WHERE user_id = ? AND is_default = 1");
            $chk->execute([$uid]);
            if (!$chk->fetch()) {
                $db->prepare("UPDATE `user_addresses` SET is_default = 1 WHERE user_id = ? ORDER BY id DESC LIMIT 1")->execute([$uid]);
            }
            echo json_encode(['status' => 'ok', 'message' => 'Address removed.']);
            exit;
        }

        if ($action === 'profile_set_default') {
            $addr_id = (int)($req['addr_id'] ?? 0);
            $db->prepare("UPDATE `user_addresses` SET is_default = 0 WHERE user_id = ?")->execute([$uid]);
            $db->prepare("UPDATE `user_addresses` SET is_default = 1 WHERE id = ? AND user_id = ?")->execute([$addr_id, $uid]);
            echo json_encode(['status' => 'ok', 'message' => 'Default address set.']);
            exit;
        }
    }

    // =========================================================================
    // 4. BLOG INIT, LIKE & MODAL POST
    // =========================================================================
    if ($action === 'blog_init') {
        $single_post = null;
        if (!empty($req['id']) || !empty($req['slug'])) {
            if (!empty($req['id'])) {
                $st = $db->prepare("SELECT * FROM blog_posts WHERE id = ? AND status = 'published' LIMIT 1");
                $st->execute([(int)$req['id']]);
            } else {
                $st = $db->prepare("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published' LIMIT 1");
                $st->execute([trim((string)$req['slug'])]);
            }
            $single_post = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($single_post) {
                $db->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = ?")->execute([$single_post['id']]);
            }
        }
        $published_posts = $db->query("SELECT * FROM blog_posts WHERE status = 'published' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['status' => 'success', 'single_post' => $single_post, 'published_posts' => $published_posts]);
        exit;
    }

    if ($action === 'like_post') {
        $post_id = (int)($req['id'] ?? 0);
        if ($post_id > 0) {
            $db->prepare("UPDATE blog_posts SET likes = likes + 1 WHERE id = ?")->execute([$post_id]);
            $st = $db->prepare("SELECT likes FROM blog_posts WHERE id = ?");
            $st->execute([$post_id]);
            echo json_encode(['status' => 'success', 'likes' => (int)$st->fetchColumn()]);
            exit;
        }
    }

    if ($action === 'get_modal_post') {
        $post_id = (int)($req['id'] ?? 0);
        if ($post_id > 0) {
            $db->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = ?")->execute([$post_id]);
            $st = $db->prepare("SELECT * FROM blog_posts WHERE id = ? AND status = 'published' LIMIT 1");
            $st->execute([$post_id]);
            $post = $st->fetch(PDO::FETCH_ASSOC);
            if ($post) {
                echo json_encode(['status' => 'success', 'post' => $post]);
                exit;
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'Post not found']);
        exit;
    }

    // =========================================================================
    // 5. PERSONAL STAFF & MAIDS INIT
    // =========================================================================
    if ($action === 'maids_init') {
        $uid = resolveCustomerId($req);
        $userAddresses = [];
        if ($uid > 0) {
            $stAddr = $db->prepare("SELECT id, label, address, landmark, pincode FROM `user_addresses` WHERE user_id = ? ORDER BY is_default DESC, updated_at DESC");
            $stAddr->execute([$uid]);
            $raw = $stAddr->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $seen = [];
            foreach ($raw as $a) {
                $k = strtolower(trim($a['address'] ?? '')) . '|' . trim($a['pincode'] ?? '');
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $userAddresses[] = $a;
                }
            }
        }
        $subcategories = $db->query("
            SELECT sc.* FROM `serveasy-subcategories` sc
            JOIN `serveasy-categories` c ON sc.category_id = c.id
            WHERE c.slug = 'personal-staff-&-maids' AND sc.is_active = 1
            ORDER BY sc.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $profiles = $db->query("
            SELECT mp.*, sp.id AS sp_provider_id, COALESCE(c.company_name, sp.full_name) AS company_name
            FROM `provider_profiles` mp
            JOIN `serveasy-providers` sp ON mp.provider_id = sp.id
            LEFT JOIN `serveasy-company` c ON c.provider_id = sp.id
            WHERE mp.status = 'Approved'
            ORDER BY mp.years_of_experience DESC, mp.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($profiles as &$p) {
            $pid = (int)$p['id'];
            $stSk = $db->prepare("SELECT skill_name FROM `provider_skills` WHERE profile_id = :pid");
            $stSk->execute([':pid' => $pid]);
            $p['skills'] = $stSk->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $stCS = $db->prepare("SELECT skill_name FROM `provider_custom_skills` WHERE profile_id = :pid");
            $stCS->execute([':pid' => $pid]);
            $p['custom_skills'] = $stCS->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $stSub = $db->prepare("SELECT sc.id, sc.name FROM `provider_services` ps JOIN `serveasy-subcategories` sc ON ps.subcategory_id = sc.id WHERE ps.profile_id = :pid");
            $stSub->execute([':pid' => $pid]);
            $p['subcategories'] = $stSub->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stAv = $db->prepare("SELECT day_of_week, time_slot, is_available, emergency_availability FROM `provider_availability` WHERE profile_id = :pid");
            $stAv->execute([':pid' => $pid]);
            $p['availability'] = $stAv->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stAr = $db->prepare("SELECT area_name, pincode FROM `provider_service_areas` WHERE profile_id = :pid");
            $stAr->execute([':pid' => $pid]);
            $p['areas'] = $stAr->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        unset($p);

        echo json_encode([
            'status'        => 'success',
            'userAddresses' => $userAddresses,
            'subcategories' => $subcategories,
            'profiles'      => $profiles,
        ]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
