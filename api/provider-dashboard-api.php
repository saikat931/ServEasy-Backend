<?php
/**
 * ServEasy Central Service Provider Dashboard API
 * Hosts all Provider Dashboard queries & AJAX actions centrally on the Backend domain
 * so standalone Provider frontends on separate Hostinger domains require ZERO database credentials.
 */
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// 1. Authenticate Provider via JWT (Authorization: Bearer, X-Auth-Token, or session)
$dealer_id = 0;
$token = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
    $token = trim($m[1]);
}
if (empty($token) && !empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
    $token = trim($_SERVER['HTTP_X_AUTH_TOKEN']);
}
if (empty($token) && !empty($_REQUEST['access_token'])) {
    $token = trim($_REQUEST['access_token']);
}

if (!empty($token)) {
    try {
        $appConfig = require dirname(__DIR__) . '/config/app.php';
        $payload = \App\Helpers\JWT::decode($token, $appConfig['jwt']['secret']);
        if (!empty($payload['user_id']) && strtoupper($payload['role'] ?? '') === 'PROVIDER') {
            $dealer_id = (int)$payload['user_id'];
        }
    } catch (\Throwable $e) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired provider token: ' . $e->getMessage()]);
        exit();
    }
} elseif (!empty($_SESSION['dealer']['id'])) {
    $dealer_id = (int)$_SESSION['dealer']['id'];
}

if ($dealer_id <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Provider authentication required']);
    exit();
}

$dealer = [
    'id'          => $dealer_id,
    'provider_id' => $dealer_id,
    'name'        => $_SESSION['dealer']['name'] ?? 'Service Provider',
    'email'       => $_SESSION['dealer']['email'] ?? '',
    'phone'       => $_SESSION['dealer']['phone'] ?? '',
    'role'        => 'provider',
];
$_SESSION['dealer'] = $dealer;

try {
    $pdo = \App\Helpers\DB::getConnection();
    // Helper to calculate provider profile completion (Must have: Phone, Email, Address, ID Proof = 100%)
    function getProviderProfileProgress($pdo, $dealer_id) {
        $p_stmt = $pdo->prepare("
            SELECT sp.*
            FROM `serveasy-providers` sp
            WHERE sp.id = ?
        ");
        $p_stmt->execute([$dealer_id]);
        $prov = $p_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $spa_address = '';
        if (!empty($prov['id'])) {
            $spa_stmt = $pdo->prepare("SELECT address FROM `service_providers_address` WHERE provider_id = ? ORDER BY is_default DESC, id DESC LIMIT 1");
            $spa_stmt->execute([$prov['id']]);
            $spa_address = $spa_stmt->fetchColumn() ?: '';
        }

        $phone = trim($prov['phone'] ?? '');
        $phone_digits = preg_replace('/[^0-9]/', '', $phone);
        $has_phone = (!empty($phone_digits) && strlen($phone_digits) >= 10);

        $email = trim($prov['email'] ?? '');
        $has_email = (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        $address = trim($prov['location_address'] ?? ($spa_address ?: ''));
        $has_address = (!empty($address) && strlen($address) >= 5);

        $v_stmt = $pdo->prepare("
            SELECT *
            FROM `id_verifications`
            WHERE user_id = ?
            ORDER BY verification_id DESC
            LIMIT 1
        ");
        $v_stmt->execute([$dealer_id]);
        $ver = $v_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($ver) {
            $id_status = $ver['verification_status'] ?? 'under_review';
        } elseif (($prov['verified_status'] ?? '') === 'approved') {
            $id_status = 'approved';
        } else {
            $id_status = 'not_submitted';
        }
        $has_id_proof = ($id_status === 'approved');

        $id_badge = 'Missing';
        $id_badge_class = 'danger';
        $id_action_label = 'Upload ID Proof';
        if ($has_id_proof || $id_status === 'approved') {
            $id_badge = 'Approved';
            $id_badge_class = 'success';
            $id_action_label = 'Verified';
        } elseif ($id_status === 'pending' || $id_status === 'under_review') {
            $id_badge = 'Under Review';
            $id_badge_class = 'warning';
            $id_action_label = 'View Status';
        } elseif ($id_status === 'rejected') {
            $id_badge = 'Rejected';
            $id_badge_class = 'danger';
            $id_action_label = 'Resubmit ID';
        }

        $pillars = [
            'phone' => [
                'name' => 'Phone Number',
                'done' => $has_phone,
                'val'  => $phone,
                'percent' => 25,
                'badge' => $has_phone ? 'Verified' : 'Missing',
                'badge_class' => $has_phone ? 'success' : 'danger',
                'action_tab' => 'profile',
                'action_label' => $has_phone ? 'Edit' : 'Add Phone'
            ],
            'email' => [
                'name' => 'Email Address',
                'done' => $has_email,
                'val'  => $email,
                'percent' => 25,
                'badge' => $has_email ? 'Verified' : 'Missing',
                'badge_class' => $has_email ? 'success' : 'danger',
                'action_tab' => 'profile',
                'action_label' => $has_email ? 'Edit' : 'Add Email'
            ],
            'address' => [
                'name' => 'Address Details',
                'done' => $has_address,
                'val'  => $address,
                'percent' => 25,
                'badge' => $has_address ? 'Completed' : 'Missing',
                'badge_class' => $has_address ? 'success' : 'danger',
                'action_tab' => 'profile',
                'action_label' => $has_address ? 'Edit' : 'Add Address'
            ],
            'id_proof' => [
                'name' => 'Government ID Proof',
                'done' => $has_id_proof,
                'val'  => $ver ? (($ver['id_type'] ?? 'ID') . ': ' . ($ver['id_number'] ?? '')) : '',
                'status' => $id_status,
                'percent' => 25,
                'badge' => $id_badge,
                'badge_class' => $id_badge_class,
                'action_tab' => 'verification',
                'action_label' => $id_action_label
            ],
        ];

        $total_percent = 0;
        $missing_items = [];
        foreach ($pillars as $key => $p) {
            if ($p['done']) {
                $total_percent += $p['percent'];
            } else {
                if ($key === 'id_proof') {
                    if ($id_status === 'pending' || $id_status === 'under_review') {
                        $missing_items[] = 'ID Proof (Admin Approval Pending)';
                    } elseif ($id_status === 'rejected') {
                        $missing_items[] = 'ID Proof (Rejected - Resubmit)';
                    } else {
                        $missing_items[] = 'Government ID Proof';
                    }
                } else {
                    $missing_items[] = $p['name'];
                }
            }
        }

        return [
            'percentage' => min(100, $total_percent),
            'is_100'     => ($total_percent >= 100),
            'pillars'    => $pillars,
            'missing'    => $missing_items,
            'phone'      => $phone,
            'email'      => $email,
            'address'    => $address,
            'id_status'  => $id_status,
            'ver_record' => $ver,
            'prov_record' => $prov
        ];
    }

    $profile_progress = getProviderProfileProgress($pdo, $dealer_id);
    $ver_record = $profile_progress['ver_record'];
    $prov_record = $profile_progress['prov_record'];
    $verification_status = $profile_progress['id_status'];

    // Query Dealer Registration Date from serveasy-providers
    $user_stmt = $pdo->prepare("
        SELECT created_at 
        FROM `serveasy-providers` 
        WHERE id = ?
    ");
    $user_stmt->execute([$dealer_id]);
    $created_at = $user_stmt->fetchColumn() ?: 'N/A';

    // Determine active tab from URL query parameter
    $open_tab = $_GET['tab'] ?? '';
    $valid_tabs = ['dashboard', 'products', 'orders', 'wallet', 'profile', 'verification'];
    if (!in_array($open_tab, $valid_tabs)) {
        $open_tab = 'dashboard';
    }

    // AJAX Handlers (Support both 'ajax_action' and 'action' request parameters)
    $action = $_REQUEST['ajax_action'] ?? $_REQUEST['action'] ?? null;
    if ($action !== null) {

        if ($action === 'fetch_stats') {
            $provider_id = (int)$dealer_id;

            // Stats for provider
            $total_services = $pdo->prepare("SELECT COUNT(*) FROM `serveasy-services` WHERE provider_id = ?");
            $total_services->execute([$provider_id]);
            $prod_count = (int)$total_services->fetchColumn();

            // Bookings for provider
            $total_bookings = $pdo->prepare("SELECT COUNT(*) FROM vehicle_wash WHERE provider_id = ?");
            $total_bookings->execute([$provider_id]);
            $order_count = (int)$total_bookings->fetchColumn();

            // Total Sales
            $total_sales = $pdo->prepare("SELECT SUM(total_amount) FROM vehicle_wash WHERE provider_id = ? AND LOWER(status) = 'completed'");
            $total_sales->execute([$provider_id]);
            $sales_amount = (float)($total_sales->fetchColumn() ?: 0);

            // Recent live bookings for overview table (active / actionable bookings only; completed & cancelled/rejected orders live in My Bookings)
            $recent_stmt = $pdo->prepare("
                SELECT b.id, b.booking_number, b.schedule_date, b.schedule_time,
                       b.total_amount, b.status, b.started_at, b.completed_at, b.created_at,
                       s.name as service_name,
                       su.full_name as customer_name
                FROM vehicle_wash b
                LEFT JOIN `serveasy-services` s ON b.service_id = s.id
                LEFT JOIN site_user su ON b.customer_id = su.id
                LEFT JOIN reviews r ON r.booking_id = b.id
                WHERE b.provider_id = ? AND LOWER(b.status) NOT IN ('completed', 'cancelled', 'rejected')
                ORDER BY b.id DESC LIMIT 5
            ");
            $recent_stmt->execute([$provider_id]);
            $recent_bookings = $recent_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            header('Content-Type: application/json');
            echo json_encode([
                'products'        => $prod_count,
                'orders'          => $order_count,
                'sales'           => number_format($sales_amount, 2),
                'recent_bookings' => $recent_bookings
            ]);
            exit();
        }

        if ($action === 'fetch_products') {
            try {
                $pdo->exec("ALTER TABLE `serveasy-services` ADD COLUMN `service_mode` ENUM('online', 'offline', 'both') DEFAULT 'both'");
            } catch (Exception $e) { }

            $provider_id = (int)$dealer_id;

            $stmt = $pdo->prepare("SELECT s.id, s.name, s.description, s.works_list, s.base_price as price, s.duration_minutes as qty_in_stock, sub.name as category_name, s.is_active, s.approval_status, s.service_mode, s.service_range_km FROM `serveasy-services` s LEFT JOIN `serveasy-subcategories` sub ON s.subcategory_id = sub.id WHERE s.provider_id = ? ORDER BY s.id DESC");
            $stmt->execute([$provider_id]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $bStmt = $pdo->prepare("SELECT b.branch_name FROM `serveasy-provider-branches` b WHERE b.provider_id = ?");
            $bStmt->execute([$provider_id]);
            $branches = $bStmt->fetchAll(PDO::FETCH_COLUMN);
            $branchList = !empty($branches) ? implode(', ', $branches) : '';
            foreach ($products as &$product) {
                $product['branches'] = $branchList;
            }

            header('Content-Type: application/json');
            echo json_encode($products);
            exit();
        }

        if ($action === 'fetch_orders') {
            $provider_id = (int)$dealer_id;

            // Join customer info â€” prefer site_user name, fall back to users table
            $stmt = $pdo->prepare("
                SELECT b.id, b.booking_number,
                       b.schedule_date as order_date, b.schedule_time,
                       b.total_amount as order_total, b.status as status_name,
                       b.started_at, b.completed_at, b.created_at,
                       b.payment_method, b.payment_status,
                       b.landmark, b.pincode, b.vehicle_type, b.vehicle_color,
                       s.name as service_name, s.base_price as service_price,
                       pkg.name as package_name, pkg.price as package_price,
                       su.full_name as customer_name,
                       su.phone_number as customer_phone,
                       su.email_address as customer_email,
                       b.vehicle_brand, b.vehicle_model, b.vehicle_reg_no,
                       b.address, b.location_type, b.extras
                FROM vehicle_wash b
                JOIN `serveasy-services` s ON b.service_id = s.id
                LEFT JOIN `serveasy-service_packages` pkg ON b.package_id = pkg.id
                LEFT JOIN site_user su ON b.customer_id = su.id
                LEFT JOIN reviews r ON r.booking_id = b.id
                WHERE b.provider_id = ?
                ORDER BY 
                    CASE LOWER(b.status)
                        WHEN 'in_progress' THEN 1
                        WHEN 'pending' THEN 2
                        WHEN 'accepted' THEN 3
                        WHEN 'completed' THEN 4
                        WHEN 'cancelled' THEN 5
                        WHEN 'rejected' THEN 6
                        ELSE 7
                    END ASC,
                    b.id DESC
            ");
            $stmt->execute([$provider_id]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: application/json');
            echo json_encode($orders);
            exit();
        }

        if ($action === 'fetch_profile') {
            // Fetch account info strictly from serveasy-providers & serveasy-company
            $stmt = $pdo->prepare("
                SELECT
                    sp.full_name,
                    sp.email,
                    sp.phone AS phone_number,
                    COALESCE(c.company_name, '') AS company_name,
                    sp.verified_status,
                    sp.is_active
                FROM `serveasy-providers` sp
                LEFT JOIN `serveasy-company` c ON c.provider_id = sp.id
                WHERE sp.id = ?
            ");
            $stmt->execute([$dealer_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $profile = null;
            if ($row) {
                $profile = [
                    'username'        => $row['email'] ?: $row['phone_number'],
                    'full_name'       => $row['full_name'] ?: 'Service Provider',
                    'email'           => $row['email'],
                    'phone_number'    => $row['phone_number'],
                    'company_name'    => $row['company_name'] ?? '',
                    'verified_status' => $row['verified_status'] ?? 'pending',
                    'status'          => $row['is_active'] ? 'active' : 'inactive',
                ];
            }

            header('Content-Type: application/json');
            echo json_encode($profile);
            exit();
        }

        if ($action === 'fetch_categories') {
            $stmt = $pdo->query("SELECT id, name as category_name FROM `serveasy-subcategories` ORDER BY name ASC");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: application/json');
            echo json_encode($categories);
            exit();
        }

        if ($action === 'fetch_profile_progress') {
            $progress = getProviderProfileProgress($pdo, $dealer_id);
            header('Content-Type: application/json');
            echo json_encode([
                'status'     => 'success',
                'data'       => $progress,
                'percentage' => $progress['percentage'],
                'is_100'     => $progress['is_100'],
                'pillars'    => $progress['pillars'],
                'missing'    => $progress['missing']
            ]);
            exit();
        }

        if ($action === 'add_product') {
            $progress = getProviderProfileProgress($pdo, $dealer_id);
            if (!$progress['is_100']) {
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Profile must be 100% complete and Government ID Proof must be APPROVED by Admin/Super Admin before adding services. Currently ' . $progress['percentage'] . '% complete. Missing / Incomplete: ' . implode(', ', $progress['missing'])
                ]);
                exit();
            }

            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            $category_id = (int)($_POST['category_id'] ?? 0);
            $price = (float)($_POST['price'] ?? 0.00);
            $qty_in_stock = (int)($_POST['qty_in_stock'] ?? 60); // duration in minutes
            $service_mode = $_POST['service_mode'] ?? 'offline';
            if (in_array($category_id, [1, 2, 41]) || stripos($name, 'wash') !== false) {
                $service_mode = 'offline';
            }
            $sku = $_POST['sku'] ?? '';
            $service_range_km = (float)($_POST['service_range_km'] ?? 10.00);

            if (empty($name) || empty($description) || !$category_id) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
                exit();
            }

            $provider_id = (int)$dealer_id;

            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));

            // Process works list / inclusions
            $raw_works = $_POST['works_list'] ?? '';
            $works_list_arr = [];
            if (!empty($raw_works)) {
                $lines = preg_split('/[\r\n,]+/', $raw_works);
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed !== '') {
                        $works_list_arr[] = $trimmed;
                    }
                }
            }
            $works_list_json = !empty($works_list_arr) ? json_encode($works_list_arr, JSON_UNESCAPED_UNICODE) : null;

            try {
                $pdo->beginTransaction();

                // Insert into services table
                $stmt = $pdo->prepare("
                    INSERT INTO `serveasy-services` (provider_id, subcategory_id, name, slug, description, works_list, base_price, duration_minutes, is_active, approval_status, service_mode, service_range_km)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'pending', ?, ?)
                ");
                $stmt->execute([
                    $provider_id,
                    $category_id,
                    $name,
                    $slug,
                    $description,
                    $works_list_json,
                    $price,
                    $qty_in_stock,
                    $service_mode,
                    $service_range_km
                ]);
                $service_id = $pdo->lastInsertId();

                // Add addons
                $addon_names = $_POST['addon_name'] ?? [];
                $addon_prices = $_POST['addon_price'] ?? [];
                if (!empty($addon_names) && is_array($addon_names)) {
                    $addonStmt = $pdo->prepare("INSERT INTO `serveasy-service_addons` (service_id, name, price) VALUES (?, ?, ?)");
                    for ($i = 0; $i < count($addon_names); $i++) {
                        $a_name = trim($addon_names[$i]);
                        $a_price = (float)($addon_prices[$i] ?? 0.00);
                        if ($a_name !== '') {
                            $addonStmt->execute([$service_id, $a_name, $a_price]);
                        }
                    }
                }

                // Add default placeholder service image
                $stmtImg = $pdo->prepare("
                    INSERT INTO `serveasy-service_images` (service_id, image_url, is_primary)
                    VALUES (?, './assets/images/service-placeholder.jpg', 1)
                ");
                $stmtImg->execute([$service_id]);

                $pdo->commit();

                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Service submitted successfully! It is pending approval by Admin or Super Admin and will go LIVE once accepted.']);
                exit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
                exit();
            }
        }

        if ($action === 'fetch_product') {
            $id = (int)$_GET['id'];
            $provider_id = (int)$dealer_id;

            $stmt = $pdo->prepare("SELECT s.id, s.name, s.description, s.works_list, s.base_price as price, s.duration_minutes as qty_in_stock, s.subcategory_id as category_id, s.service_mode, s.service_range_km FROM `serveasy-services` s WHERE s.id = ? AND s.provider_id = ? LIMIT 1");
            $stmt->execute([$id, $provider_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($product) {
                $product['branch_ids'] = [];

                // Fetch addons
                $addonStmt = $pdo->prepare("SELECT id, name, price FROM `serveasy-service_addons` WHERE service_id = ? ORDER BY id ASC");
                $addonStmt->execute([$id]);
                $product['addons'] = $addonStmt->fetchAll(PDO::FETCH_ASSOC);
                
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'data' => $product]);
            } else {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Service not found.']);
            }
            exit();
        }

        if ($action === 'edit_product') {
            $id = (int)($_POST['id'] ?? 0);
            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            $category_id = (int)($_POST['category_id'] ?? 0);
            $price = (float)($_POST['price'] ?? 0.00);
            $qty_in_stock = (int)($_POST['qty_in_stock'] ?? 60);
            $service_mode = $_POST['service_mode'] ?? 'offline';
            if (in_array($category_id, [1, 2, 41]) || stripos($name, 'wash') !== false) {
                $service_mode = 'offline';
            }
            $service_range_km = (float)($_POST['service_range_km'] ?? 10.00);

            if (empty($name) || empty($description) || !$category_id || !$id) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
                exit();
            }

            $provider_id = (int)$dealer_id;
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));

            // Process works list / inclusions
            $raw_works = $_POST['works_list'] ?? '';
            $works_list_arr = [];
            if (!empty($raw_works)) {
                $lines = preg_split('/[\r\n,]+/', $raw_works);
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed !== '') {
                        $works_list_arr[] = $trimmed;
                    }
                }
            }
            $works_list_json = !empty($works_list_arr) ? json_encode($works_list_arr, JSON_UNESCAPED_UNICODE) : null;

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    UPDATE `serveasy-services`
                    SET subcategory_id = ?, name = ?, slug = ?, description = ?, works_list = ?, base_price = ?, duration_minutes = ?, service_mode = ?, service_range_km = ?
                    WHERE id = ? AND provider_id = ?
                ");
                $stmt->execute([$category_id, $name, $slug, $description, $works_list_json, $price, $qty_in_stock, $service_mode, $service_range_km, $id, $provider_id]);

                // Update addons
                $pdo->prepare("DELETE FROM `serveasy-service_addons` WHERE service_id = ?")->execute([$id]);
                $addon_names = $_POST['addon_name'] ?? [];
                $addon_prices = $_POST['addon_price'] ?? [];
                if (!empty($addon_names) && is_array($addon_names)) {
                    $addonStmt = $pdo->prepare("INSERT INTO `serveasy-service_addons` (service_id, name, price) VALUES (?, ?, ?)");
                    for ($i = 0; $i < count($addon_names); $i++) {
                        $a_name = trim($addon_names[$i]);
                        $a_price = (float)($addon_prices[$i] ?? 0.00);
                        if ($a_name !== '') {
                            $addonStmt->execute([$id, $a_name, $a_price]);
                        }
                    }
                }

                $pdo->commit();
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Service updated successfully!']);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit();
        }

        if ($action === 'delete_product') {
            $id = (int)($_POST['id'] ?? 0);
            $provider_id = (int)$dealer_id;

            try {
                $pdo->prepare("DELETE FROM `serveasy-service_images` WHERE service_id = ?")->execute([$id]);
                $stmt = $pdo->prepare("DELETE FROM `serveasy-services` WHERE id = ? AND provider_id = ?");
                $stmt->execute([$id, $provider_id]);
                
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Service deleted successfully!']);
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit();
        }

        if ($action === 'toggle_service_mode') {
            $id = (int)$_POST['id'];
            $service_mode = $_POST['service_mode'];
            $provider_id = (int)$dealer_id;

            $stmt = $pdo->prepare("UPDATE `serveasy-services` SET service_mode = ? WHERE id = ? AND provider_id = ?");
            if ($stmt->execute([$service_mode, $id, $provider_id])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success']);
            } else {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Failed to toggle mode']);
            }
            exit();
        }

        if ($action === 'toggle_product_status') {
            $id = (int)$_POST['id'];
            $is_active = (int)$_POST['is_active'];
            $provider_id = (int)$dealer_id;

            // Check if service is approved before allowing activation
            $check_stmt = $pdo->prepare("SELECT approval_status FROM `serveasy-services` WHERE id = ? AND provider_id = ?");
            $check_stmt->execute([$id, $provider_id]);
            $app_status = $check_stmt->fetchColumn();

            if ($is_active == 1 && $app_status !== 'approved') {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'This service cannot be made online because it has not yet been accepted by an Admin or Super Admin.']);
                exit();
            }

            $stmt = $pdo->prepare("UPDATE `serveasy-services` SET is_active = ? WHERE id = ? AND provider_id = ?");
            $stmt->execute([$is_active, $id, $provider_id]);
            
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Status updated successfully!']);
            exit();
        }

        if ($action === 'update_booking_status' || $action === 'cancel_booking' || $action === 'cancel' || $action === 'reject_booking') {
            $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
            $status = $_POST['status'] ?? ($_GET['status'] ?? '');
            if (empty($status)) {
                if ($action === 'cancel' || $action === 'cancel_booking') {
                    $status = 'cancelled';
                } elseif ($action === 'reject_booking') {
                    $status = 'rejected';
                }
            }
            $reason = $_POST['reason'] ?? ($_POST['remarks'] ?? ($_GET['reason'] ?? null));
            try {
                if (class_exists('\App\Services\BookingService')) {
                    $bookingService = new \App\Services\BookingService();
                    $bookingService->updateBookingStatus($id, $status, (int)$dealer_id, $reason);
                } else {
                    // Standalone Hostinger domain PDO fallback
                    $allowed = ['pending', 'accepted', 'confirmed', 'in_progress', 'completed', 'cancelled', 'rejected'];
                    $stLower = strtolower(trim($status));
                    if (!in_array($stLower, $allowed, true)) {
                        throw new \Exception("Invalid booking status: " . $status);
                    }
                    $chk = $pdo->prepare("SELECT id, status FROM vehicle_wash WHERE id = ? AND provider_id = ? LIMIT 1");
                    $chk->execute([$id, (int)$dealer_id]);
                    if (!$chk->fetch()) {
                        throw new \Exception("Booking not found or unauthorized.");
                    }
                    $extraSql = "";
                    $params = [$stLower];
                    if ($stLower === 'in_progress') {
                        $extraSql .= ", started_at = COALESCE(started_at, NOW())";
                    } elseif ($stLower === 'completed') {
                        $extraSql .= ", completed_at = COALESCE(completed_at, NOW())";
                    }
                    if (!empty($reason) && in_array($stLower, ['cancelled', 'rejected'], true)) {
                        $extraSql .= ", cancellation_reason = ?";
                        $params[] = $reason;
                    }
                    $params[] = $id;
                    $params[] = (int)$dealer_id;
                    try {
                        $pdo->prepare("UPDATE vehicle_wash SET status = ?{$extraSql} WHERE id = ? AND provider_id = ?")->execute($params);
                    } catch (\Throwable $colErr) {
                        $pdo->prepare("UPDATE vehicle_wash SET status = ? WHERE id = ? AND provider_id = ?")->execute([$stLower, $id, (int)$dealer_id]);
                    }
                }

                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Booking status updated successfully!']);
            } catch (\Throwable $e) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit();
        }

                if ($action === 'mark_booking_paid') {
            $id = (int)($_POST['id'] ?? 0);
            $provider_id = (int)$dealer_id;

            try {
                $bStmt = $pdo->prepare("SELECT id, booking_number, customer_id, provider_id, total_amount, status, payment_status FROM vehicle_wash WHERE id = ? AND provider_id = ? LIMIT 1");
                $bStmt->execute([$id, $provider_id]);
                $booking = $bStmt->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    throw new Exception("Booking not found or access denied.");
                }

                if (strtoupper($booking['payment_status'] ?? '') === 'PAID') {
                    throw new Exception("Booking is already marked as PAID.");
                }

                $pdo->beginTransaction();

                // 1. Update payment status to PAID
                $upStmt = $pdo->prepare("UPDATE vehicle_wash SET payment_status = 'PAID', payment_method = 'COD' WHERE id = ?");
                $upStmt->execute([$id]);

                // 2. Record payment row in serveasy.payments
                $stmtPay = $pdo->prepare("
                    INSERT INTO `payments` (booking_id, amount, status, payment_method, gateway_transaction_id) 
                    VALUES (:booking_id, :amount, 'completed', 'COD', :tx_id)
                ");
                $stmtPay->execute([
                    'booking_id' => $id,
                    'amount' => (float)$booking['total_amount'],
                    'tx_id' => 'COD-CASH-' . strtoupper(uniqid())
                ]);

                // 3. Record â‚¹20 site service charge for provider
                $serviceChargeAmount = 20.00;
                $stmtCharge = $pdo->prepare("
                    INSERT INTO `provider_service_charges` 
                        (provider_id, booking_id, booking_number, amount, status, created_at)
                    VALUES 
                        (:provider_id, :booking_id, :booking_number, :amount, 'PENDING', NOW())
                ");
                $stmtCharge->execute([
                    'provider_id' => $provider_id,
                    'booking_id' => $id,
                    'booking_number' => $booking['booking_number'],
                    'amount' => $serviceChargeAmount
                ]);

                // 4. Update provider_wallets service_charge_due
                $walletStmt = $pdo->prepare("
                    INSERT INTO `provider_wallets` (provider_id, service_charge_due)
                    VALUES (:pid, :charge)
                    ON DUPLICATE KEY UPDATE service_charge_due = service_charge_due + :charge_dup
                ");
                $walletStmt->execute([
                    'pid' => $provider_id,
                    'charge' => $serviceChargeAmount,
                    'charge_dup' => $serviceChargeAmount
                ]);

                // 5. SSE transactional outbox push
                try {
                    $eventRepo = new \App\Repositories\EventRepository();
                    $eventPayload = [
                        'booking_id' => $id,
                        'booking_number' => $booking['booking_number'],
                        'status' => $booking['status'],
                        'payment_status' => 'PAID',
                        'payment_method' => 'COD',
                        'amount' => (float)$booking['total_amount'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    if (!empty($booking['customer_id'])) {
                        $eventRepo->recordEvent('booking.updated', $id, 'CUSTOMER', (int)$booking['customer_id'], $eventPayload);
                    }
                    $eventRepo->recordEvent('booking.updated', $id, 'PROVIDER', $provider_id, $eventPayload);
                } catch (\Throwable $evEx) {
                    error_log("Failed to dispatch SSE event: " . $evEx->getMessage());
                }

                $pdo->commit();

                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'success',
                    'message' => "Payment marked as PAID! â‚¹{$serviceChargeAmount} site service charge has been recorded to your account.",
                    'service_charge_due' => $serviceChargeAmount
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit();
        }

        if ($action === 'pay_service_charges') {
            $provider_id = (int)$dealer_id;
            $method = strtolower($_POST['payment_method'] ?? 'wallet');

            try {
                $stmtDue = $pdo->prepare("
                    SELECT COALESCE(SUM(amount), 0) AS total_due 
                    FROM `provider_service_charges` 
                    WHERE provider_id = ? AND status = 'PENDING'
                ");
                $stmtDue->execute([$provider_id]);
                $totalDue = (float)$stmtDue->fetchColumn();

                if ($totalDue <= 0) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'success', 'message' => 'No outstanding site service charge dues!']);
                    exit();
                }

                $pdo->beginTransaction();

                if ($method === 'wallet') {
                    $stmtWal = $pdo->prepare("SELECT available_balance FROM `provider_wallets` WHERE provider_id = ?");
                    $stmtWal->execute([$provider_id]);
                    $availBal = (float)$stmtWal->fetchColumn();

                    if ($availBal < $totalDue) {
                        $pdo->rollBack();
                        header('Content-Type: application/json');
                        echo json_encode(['status' => 'error', 'message' => "Insufficient wallet balance (â‚¹" . number_format($availBal, 2) . ") to clear â‚¹" . number_format($totalDue, 2) . " dues. Please choose Online Payment."]);
                        exit();
                    }

                    $deductStmt = $pdo->prepare("
                        UPDATE `provider_wallets`
                        SET available_balance = GREATEST(0, available_balance - :dues),
                            service_charge_due = GREATEST(0, service_charge_due - :dues_dup)
                        WHERE provider_id = :pid
                    ");
                    $deductStmt->execute([
                        'dues' => $totalDue,
                        'dues_dup' => $totalDue,
                        'pid' => $provider_id
                    ]);
                } else {
                    $clearWalStmt = $pdo->prepare("
                        UPDATE `provider_wallets`
                        SET service_charge_due = 0.00
                        WHERE provider_id = ?
                    ");
                    $clearWalStmt->execute([$provider_id]);
                }

                $markStmt = $pdo->prepare("
                    UPDATE `provider_service_charges`
                    SET status = 'PAID', paid_at = NOW(), payment_method = :pm
                    WHERE provider_id = :pid AND status = 'PENDING'
                ");
                $markStmt->execute([
                    'pm' => strtoupper($method),
                    'pid' => $provider_id
                ]);

                $pdo->commit();

                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'success',
                    'message' => "Site service charge of â‚¹" . number_format($totalDue, 2) . " cleared successfully! You can now accept new bookings.",
                    'cleared_amount' => $totalDue
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit();
        }

        if ($action === 'get_service_charges') {
            $provider_id = (int)$dealer_id;
            try {
                $stmt = $pdo->prepare("
                    SELECT id, booking_id, booking_number, amount, status, paid_at, payment_method, created_at
                    FROM `provider_service_charges`
                    WHERE provider_id = ?
                    ORDER BY id DESC
                ");
                $stmt->execute([$provider_id]);
                $charges = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmtDue = $pdo->prepare("
                    SELECT COALESCE(SUM(amount), 0) AS total_due 
                    FROM `provider_service_charges` 
                    WHERE provider_id = ? AND status = 'PENDING'
                ");
                $stmtDue->execute([$provider_id]);
                $totalDue = (float)$stmtDue->fetchColumn();

                $stmtWal = $pdo->prepare("SELECT available_balance FROM `provider_wallets` WHERE provider_id = ?");
                $stmtWal->execute([$provider_id]);
                $availBal = (float)$stmtWal->fetchColumn();

                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'success',
                    'total_due' => $totalDue,
                    'available_balance' => $availBal,
                    'can_accept_bookings' => ($totalDue <= 0),
                    'charges' => $charges
                ]);
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit();
        }

        if ($action === 'fetch_booking_details') {
            $id = (int)($_GET['id'] ?? 0);
            $provider_id = (int)$dealer_id;

            $stmt = $pdo->prepare("
                SELECT b.id, b.booking_number, b.customer_id, b.service_id, b.package_id, b.provider_id,
                       b.schedule_date, b.schedule_time, b.address, b.total_amount, b.status, b.notes,
                       b.vehicle_type, b.vehicle_brand, b.vehicle_model, b.vehicle_reg_no, b.vehicle_color,
                       b.location_type, b.landmark, b.pincode, b.extras, b.payment_method, b.payment_status,
                       b.started_at, b.completed_at, b.created_at, b.updated_at,
                       ST_X(b.location) AS latitude, ST_Y(b.location) AS longitude,
                       s.name as service_name, s.base_price as service_price, s.duration_minutes as service_duration,
                       pkg.name as package_name, pkg.price as package_price,
                       su.full_name as customer_name, su.email_address as customer_email, su.phone_number as customer_phone,
                       r.rating as review_rating, r.comment as review_comment
                FROM vehicle_wash b
                LEFT JOIN `serveasy-services` s ON b.service_id = s.id
                LEFT JOIN `serveasy-service_packages` pkg ON b.package_id = pkg.id
                LEFT JOIN site_user su ON b.customer_id = su.id
                LEFT JOIN reviews r ON r.booking_id = b.id
                WHERE b.id = ? AND b.provider_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $provider_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($booking) {
                $bLat = (float)($booking['latitude'] ?? 0);
                $bLng = (float)($booking['longitude'] ?? 0);
                if ($bLat == 0.0 && $bLng == 0.0 && !empty($booking['customer_id'])) {
                    try {
                        $cAddrStmt = $pdo->prepare("
                            SELECT latitude, longitude FROM `user_addresses`
                            WHERE user_id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0
                            ORDER BY is_default DESC, updated_at DESC LIMIT 1
                        ");
                        $cAddrStmt->execute([$booking['customer_id']]);
                        $cAddr = $cAddrStmt->fetch(PDO::FETCH_ASSOC);
                        if ($cAddr && !empty($cAddr['latitude'])) {
                            $booking['latitude'] = (float)$cAddr['latitude'];
                            $booking['longitude'] = (float)$cAddr['longitude'];
                        }
                    } catch (\Throwable $t) {}
                }
            }

            if (!$booking) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Booking not found.']);
                exit();
            }

            // Also fetch status history
            $histStmt = $pdo->prepare("
                SELECT h.id, h.booking_id, h.status, h.remarks, h.changed_by, h.created_at,
                       su.full_name as changed_by_name
                FROM booking_status_history h
                LEFT JOIN site_user su ON h.changed_by = su.id
                WHERE h.booking_id = ?
                ORDER BY h.id ASC
            ");
            $histStmt->execute([$id]);
            $booking['status_history'] = $histStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'data' => $booking]);
            exit();
        }

        if ($action === 'delete_booking') {
            $id = (int)($_POST['id'] ?? 0);
            $provider_id = (int)$dealer_id;

            $stmt = $pdo->prepare("SELECT id, status FROM vehicle_wash WHERE id = ? AND provider_id = ? LIMIT 1");
            $stmt->execute([$id, $provider_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Booking not found.']);
                exit();
            }

            $st = strtolower($booking['status']);
            if (!in_array($st, ['completed', 'rejected', 'cancelled'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Only completed or rejected bookings can be deleted.']);
                exit();
            }

            try {
                $pdo->beginTransaction();

                // Delete history
                $pdo->prepare("DELETE FROM booking_status_history WHERE booking_id = ?")->execute([$id]);

                // Delete reviews
                $pdo->prepare("DELETE FROM reviews WHERE booking_id = ?")->execute([$id]);

                // Delete payments if any
                try {
                    $pdo->prepare("DELETE FROM payments WHERE booking_id = ?")->execute([$id]);
                } catch (Exception $pe) { }

                // Delete booking
                $pdo->prepare("DELETE FROM vehicle_wash WHERE id = ? AND provider_id = ?")->execute([$id, $provider_id]);

                $pdo->commit();

                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Booking deleted successfully!']);
                exit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
                exit();
            }
        }
        if ($action === 'submit_verification') {
            $id_type = trim($_POST['id_type'] ?? '');
            $id_number = trim($_POST['id_number'] ?? '');
            $extra_document_type = trim($_POST['extra_document_type'] ?? '');
            $extra_document_number = trim($_POST['extra_document_number'] ?? '');

            if (empty($id_type) || empty($id_number)) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Primary ID Type and Document Number are required.']);
                exit();
            }

            if (empty($extra_document_type) || empty($extra_document_number)) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Extra ID Document Type and Document Number are required.']);
                exit();
            }

            $front_image = null;
            $back_image = null;
            $selfie_image = null;
            $extra_image = null;

            $upload_dir = dirname(__DIR__) . '/uploads/verifications/dealer/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            if (isset($_FILES['id_front_image']) && $_FILES['id_front_image']['error'] == 0) {
                $ext = pathinfo($_FILES['id_front_image']['name'], PATHINFO_EXTENSION);
                $filename = 'front_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['id_front_image']['tmp_name'], $upload_dir . $filename)) {
                    $front_image = 'uploads/verifications/dealer/' . $filename;
                }
            }

            if (isset($_FILES['id_back_image']) && $_FILES['id_back_image']['error'] == 0) {
                $ext = pathinfo($_FILES['id_back_image']['name'], PATHINFO_EXTENSION);
                $filename = 'back_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['id_back_image']['tmp_name'], $upload_dir . $filename)) {
                    $back_image = 'uploads/verifications/dealer/' . $filename;
                }
            }

            if (isset($_FILES['selfie_image']) && $_FILES['selfie_image']['error'] == 0) {
                $ext = pathinfo($_FILES['selfie_image']['name'], PATHINFO_EXTENSION);
                $filename = 'selfie_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['selfie_image']['tmp_name'], $upload_dir . $filename)) {
                    $selfie_image = 'uploads/verifications/dealer/' . $filename;
                }
            }

            if (isset($_FILES['extra_document_image']) && $_FILES['extra_document_image']['error'] == 0) {
                $ext = pathinfo($_FILES['extra_document_image']['name'], PATHINFO_EXTENSION);
                $filename = 'extra_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['extra_document_image']['tmp_name'], $upload_dir . $filename)) {
                    $extra_image = 'uploads/verifications/dealer/' . $filename;
                }
            }

            if (!$front_image || !$back_image || !$selfie_image || !$extra_image) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'All 4 document uploads (Primary Front, Back, Selfie with ID, and Extra Document) are required.']);
                exit();
            }

            $stmt = $pdo->prepare("
                INSERT INTO `id_verifications` 
                (user_id, id_type, id_number, id_front_image, id_back_image, selfie_image, extra_document_type, extra_document_number, extra_document_image, verification_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $dealer_id,
                $id_type,
                $id_number,
                $front_image,
                $back_image,
                $selfie_image,
                $extra_document_type,
                $extra_document_number,
                $extra_image
            ]);

            // Also update serveasy-providers status to under_review
            $pdo->prepare("UPDATE `serveasy-providers` SET verified_status = 'under_review' WHERE id = ?")->execute([$dealer_id]);

            $progress = getProviderProfileProgress($pdo, $dealer_id);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Both Primary ID and Extra Document submitted successfully! Under review.', 'progress' => $progress]);
            exit();
        }

        if ($action === 'fetch_provider_profile') {
            // Fetch account info strictly from serveasy-providers & serveasy-company
            $stmt = $pdo->prepare("
                SELECT
                    sp.id,
                    sp.full_name,
                    sp.email,
                    sp.phone,
                    COALESCE(c.company_name, '') AS company_name,
                    sp.location_address,
                    sp.latitude,
                    sp.longitude,
                    sp.service_range_km,
                    sp.verified_status,
                    sp.is_active,
                    sp.created_at
                FROM `serveasy-providers` sp
                LEFT JOIN `serveasy-company` c ON c.provider_id = sp.id
                WHERE sp.id = ?
            ");
            $stmt->execute([$dealer_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // Fetch landmark and pincode from service_providers_address
            $landmark = '';
            $pincode  = '';
            if ($row && !empty($row['id'])) {
                try {
                    $spa = $pdo->prepare("SELECT landmark, pincode FROM `service_providers_address` WHERE provider_id = ? ORDER BY is_default DESC, id DESC LIMIT 1");
                    $spa->execute([$row['id']]);
                    if ($spa_row = $spa->fetch(PDO::FETCH_ASSOC)) {
                        $landmark = $spa_row['landmark'] ?? '';
                        $pincode  = $spa_row['pincode'] ?? '';
                    }
                } catch (Exception $e) {}
            }

            $branches = [];
            if ($row && !empty($row['id'])) {
                $bstmt = $pdo->prepare("SELECT * FROM `serveasy-provider-branches` WHERE provider_id = ? ORDER BY id ASC");
                $bstmt->execute([$row['id']]);
                $branches = $bstmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $progress = getProviderProfileProgress($pdo, $dealer_id);

            $raw_name = trim($row['full_name'] ?? ($progress['prov_record']['su_name'] ?? ''));
            $clean_name = ($raw_name === 'Service Provider') ? '' : $raw_name;

            header('Content-Type: application/json');
            echo json_encode([
                'full_name'        => $clean_name,
                'email'            => $row['email']            ?? ($progress['prov_record']['su_email'] ?? ''),
                'phone'            => $row['phone']            ?? ($progress['prov_record']['su_phone'] ?? ''),
                'company_name'     => $row['company_name']     ?? '',
                'location_address' => $row['location_address'] ?? '',
                'landmark'         => $landmark,
                'pincode'          => $pincode,
                'latitude'         => $row['latitude']         ?? '',
                'longitude'        => $row['longitude']        ?? '',
                'service_range_km' => $row['service_range_km'] ?? 10,
                'verified_status'  => $row['verified_status']  ?? $progress['id_status'],
                'status'           => ($row['is_active'] ?? 1) ? 'active' : 'inactive',
                'branches'         => $branches,
                'progress'         => $progress
            ]);
            exit();
        }

        if ($action === 'save_provider_profile') {
            $company_name     = trim($_POST['company_name'] ?? '');
            $full_name        = trim($_POST['full_name'] ?? '');
            $email            = trim($_POST['email'] ?? '');
            $phone            = trim($_POST['phone'] ?? '');
            $location_address = trim($_POST['location_address'] ?? '');
            $landmark         = trim($_POST['landmark'] ?? '');
            $pincode          = trim($_POST['pincode'] ?? '');
            $latitude         = !empty($_POST['latitude'])  ? (float)$_POST['latitude']  : null;
            $longitude        = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
            $service_range_km = max(1, min(500, (float)($_POST['service_range_km'] ?? 10)));

            // Update serveasy-providers directly
            $pdo->prepare("
                UPDATE `serveasy-providers`
                SET full_name = ?, email = ?, phone = ?, location_address = ?, latitude = ?, longitude = ?, service_range_km = ?
                WHERE id = ?
            ")->execute([$full_name, $email, $phone, $location_address, $latitude, $longitude, $service_range_km, $dealer_id]);

            // Save company details in serveasy-company (connected via provider_id)
            if ($company_name !== '') {
                $compStmt = $pdo->prepare("SELECT id FROM `serveasy-company` WHERE provider_id = ? LIMIT 1");
                $compStmt->execute([$dealer_id]);
                if ($compStmt->fetchColumn()) {
                    $pdo->prepare("UPDATE `serveasy-company` SET company_name = ? WHERE provider_id = ?")->execute([$company_name, $dealer_id]);
                } else {
                    $pdo->prepare("INSERT INTO `serveasy-company` (provider_id, company_name) VALUES (?, ?)")->execute([$dealer_id, $company_name]);
                }
            }

            // Sync to service_providers_address if location_address provided
            if ($location_address !== '') {
                try {
                    $prov_pk = $dealer_id;
                    if ($prov_pk) {
                        $chk_spa = $pdo->prepare("SELECT id FROM `service_providers_address` WHERE provider_id = ? AND is_default = 1");
                        $chk_spa->execute([$prov_pk]);
                        $spa_id = $chk_spa->fetchColumn();
                        if ($spa_id) {
                            $pdo->prepare("UPDATE `service_providers_address` SET address = ?, landmark = ?, pincode = ?, latitude = ?, longitude = ? WHERE id = ?")->execute([$location_address, $landmark, $pincode, $latitude, $longitude, $spa_id]);
                        } else {
                            $pdo->prepare("INSERT INTO `service_providers_address` (provider_id, label, address, landmark, pincode, latitude, longitude, is_default) VALUES (?, 'Primary Location', ?, ?, ?, ?, ?, 1)")->execute([$prov_pk, $location_address, $landmark, $pincode, $latitude, $longitude]);
                        }
                    }
                } catch (Exception $e) {}
            }

            if ($full_name) {
                $_SESSION['dealer']['name'] = $full_name;
            }
            if ($email) {
                $_SESSION['dealer']['email'] = $email;
            }
            if ($phone) {
                $_SESSION['dealer']['phone'] = $phone;
            }

            $progress = getProviderProfileProgress($pdo, $dealer_id);

            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Profile saved successfully!', 'progress' => $progress, 'profile_progress' => $progress]);
            exit();
        }

        if ($action === 'register_company') {
            $company_name = trim($_POST['company_name'] ?? '');
            if (empty($company_name)) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Company name is required.']);
                exit();
            }
            if (strlen($company_name) < 2) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Company name must be at least 2 characters.']);
                exit();
            }

            $compStmt = $pdo->prepare("SELECT id FROM `serveasy-company` WHERE provider_id = ? LIMIT 1");
            $compStmt->execute([$dealer_id]);
            $comp_id = $compStmt->fetchColumn();

            if (!$comp_id) {
                $pdo->prepare("INSERT INTO `serveasy-company` (provider_id, company_name) VALUES (?, ?)")->execute([$dealer_id, $company_name]);
            } else {
                $pdo->prepare("UPDATE `serveasy-company` SET company_name = ? WHERE provider_id = ?")->execute([$company_name, $dealer_id]);
            }

            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'message' => 'Company registered successfully! You can now add branch locations.',
                'company_name' => $company_name
            ]);
            exit();
        }

        if ($action === 'add_branch') {
            $branch_name = trim($_POST['branch_name'] ?? '');
            $address     = trim($_POST['address'] ?? '');
            $phone       = trim($_POST['phone'] ?? '');
            $latitude    = !empty($_POST['latitude'])  ? (float)$_POST['latitude']  : null;
            $longitude   = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

            if (empty($branch_name)) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Branch name is required.']);
                exit();
            }

            // Check if provider has a registered company
            $compStmt = $pdo->prepare("SELECT id, company_name FROM `serveasy-company` WHERE provider_id = ? LIMIT 1");
            $compStmt->execute([$dealer_id]);
            $compRow = $compStmt->fetch(PDO::FETCH_ASSOC);
            $company_id = $compRow['id'] ?? null;
            $company_name = trim($compRow['company_name'] ?? '');

            if (empty($company_name) || !$company_id) {
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Individual service providers cannot add branch locations. You must register a company first.',
                    'requires_company' => true
                ]);
                exit();
            }

            $pdo->prepare("
                INSERT INTO `serveasy-provider-branches` (provider_id, company_id, branch_name, address, latitude, longitude, phone)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$dealer_id, $company_id, $branch_name, $address, $latitude, $longitude, $phone]);

            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Branch added successfully!']);
            exit();
        }


        if ($action === 'fetch_wallet_data') {
            $provider_id = (int)$dealer_id;

            // 1. Fetch or initialize provider wallet
            $wStmt = $pdo->prepare("SELECT * FROM `provider_wallets` WHERE provider_id = ? LIMIT 1");
            $wStmt->execute([$provider_id]);
            $wallet = $wStmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                $initStmt = $pdo->prepare("
                    INSERT INTO `provider_wallets` 
                    (provider_id, available_balance, pending_clearance, total_earned, total_withdrawn) 
                    VALUES (?, 0.00, 0.00, 0.00, 0.00)
                ");
                $initStmt->execute([$provider_id]);
                $wallet = [
                    'provider_id' => $provider_id,
                    'available_balance' => '0.00',
                    'pending_clearance' => '0.00',
                    'total_earned' => '0.00',
                    'total_withdrawn' => '0.00'
                ];
            }

            // 2. Fetch linked bank account
            $bStmt = $pdo->prepare("
                SELECT id, account_holder_name, account_number, ifsc_code, bank_name, upi_id, is_verified, updated_at
                FROM `provider_bank_accounts` 
                WHERE provider_id = ? 
                ORDER BY id DESC LIMIT 1
            ");
            $bStmt->execute([$provider_id]);
            $bank = $bStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            // 3. Fetch transaction history
            $tStmt = $pdo->prepare("
                SELECT t.id, t.transaction_ref, t.booking_id, t.customer_id, t.gross_amount,
                       t.gateway_fee, t.platform_commission, t.provider_net_amount,
                       COALESCE(t.gateway_name, t.payment_mode, 'RAZORPAY') as payment_gateway,
                       t.gateway_payment_id, t.status, t.failure_reason, t.created_at,
                       b.booking_number,
                       s.name as service_name,
                       COALESCE(su.full_name, 'Customer') as customer_name,
                       su.phone_number as customer_phone
                FROM `transactions` t
                LEFT JOIN vehicle_wash b ON t.booking_id = b.id
                LEFT JOIN `serveasy-services` s ON b.service_id = s.id
                LEFT JOIN site_user su ON t.customer_id = su.id
                WHERE t.provider_id = ?
                ORDER BY t.id DESC
            ");
            $tStmt->execute([$provider_id]);
            $transactions = $tStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // 4. Fetch payout requests
            $pStmt = $pdo->prepare("
                SELECT id, request_ref as payout_ref, amount, status, admin_notes, utr_number, processed_at, created_at
                FROM `payout_requests`
                WHERE provider_id = ?
                ORDER BY id DESC
            ");
            $pStmt->execute([$provider_id]);
            $payouts = $pStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // 5. Fetch platform fee settings for dynamic header and breakdown
            $pfPercent = 10.0;
            $pfType = 'percentage';
            $pfAmount = 10.0;
            try {
                $pfStmt = $pdo->prepare("
                    SELECT fee_amount, fee_type, is_active 
                    FROM `platform_settings` 
                    WHERE setting_key = 'convenience_fee' AND country_code = 'IN' 
                    LIMIT 1
                ");
                $pfStmt->execute();
                $pfRow = $pfStmt->fetch(PDO::FETCH_ASSOC);
                if ($pfRow && !empty($pfRow['is_active'])) {
                    $pfType = $pfRow['fee_type'] ?? 'percentage';
                    $pfAmount = (float)($pfRow['fee_amount'] ?? 10.0);
                    if ($pfType === 'percentage') {
                        $pfPercent = $pfAmount;
                    }
                }
            } catch (\Throwable $t) {}

            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'wallet' => $wallet,
                'bank' => $bank,
                'transactions' => $transactions,
                'payouts' => $payouts,
                'platform_fee_percent' => $pfPercent,
                'platform_fee_type' => $pfType,
                'platform_fee_amount' => $pfAmount
            ]);
            exit();
        }

        if ($action === 'save_bank_details') {
            $provider_id = (int)$dealer_id;
            $saveType = trim($_POST['save_type'] ?? 'all'); // 'bank', 'upi', or 'all'

            $chkStmt = $pdo->prepare("SELECT * FROM `provider_bank_accounts` WHERE provider_id = ? ORDER BY id DESC LIMIT 1");
            $chkStmt->execute([$provider_id]);
            $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);

            if ($saveType === 'bank') {
                $holder = trim($_POST['account_holder_name'] ?? '');
                $bankName = trim($_POST['bank_name'] ?? '');
                $accNum = trim($_POST['account_number'] ?? '');
                $accNumConfirm = trim($_POST['account_number_confirm'] ?? '');
                $ifsc = strtoupper(trim($_POST['ifsc_code'] ?? ''));

                if (empty($holder) || empty($bankName) || empty($accNum) || empty($ifsc)) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'Account holder name, bank name, account number, and IFSC code are required.']);
                    exit();
                }

                if (!empty($accNumConfirm) && $accNum !== $accNumConfirm) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'Bank account numbers do not match.']);
                    exit();
                }

                if (strlen($ifsc) !== 11) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'IFSC Code must be exactly 11 characters (e.g. HDFC0001234).']);
                    exit();
                }

                if ($existing) {
                    $uStmt = $pdo->prepare("
                        UPDATE `provider_bank_accounts`
                        SET account_holder_name = ?, bank_name = ?, account_number = ?, ifsc_code = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $uStmt->execute([$holder, $bankName, $accNum, $ifsc, $existing['id']]);
                } else {
                    $iStmt = $pdo->prepare("
                        INSERT INTO `provider_bank_accounts`
                        (provider_id, account_holder_name, bank_name, account_number, ifsc_code, upi_id, is_verified, created_at)
                        VALUES (?, ?, ?, ?, ?, NULL, 1, NOW())
                    ");
                    $iStmt->execute([$provider_id, $holder, $bankName, $accNum, $ifsc]);
                }

                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Bank account details saved successfully!']);
                exit();

            } elseif ($saveType === 'upi') {
                $upiId = strtolower(trim($_POST['upi_id'] ?? ''));

                if (empty($upiId)) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'Please enter a valid UPI ID (e.g. mobile@upi or username@bank).']);
                    exit();
                }

                if (!str_contains($upiId, '@') || strlen($upiId) < 5) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'Invalid UPI ID format. It must contain "@" (e.g. username@okhdfcbank).']);
                    exit();
                }

                if ($existing) {
                    $uStmt = $pdo->prepare("
                        UPDATE `provider_bank_accounts`
                        SET upi_id = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $uStmt->execute([$upiId, $existing['id']]);
                } else {
                    $iStmt = $pdo->prepare("
                        INSERT INTO `provider_bank_accounts`
                        (provider_id, account_holder_name, bank_name, account_number, ifsc_code, upi_id, is_verified, created_at)
                        VALUES (?, NULL, NULL, NULL, NULL, ?, 1, NOW())
                    ");
                    $iStmt->execute([$provider_id, $upiId]);
                }

                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'UPI ID linked successfully!']);
                exit();

            } else {
                // Combined fallback
                $holder = trim($_POST['account_holder_name'] ?? '');
                $accNum = trim($_POST['account_number'] ?? '');
                $ifsc = strtoupper(trim($_POST['ifsc_code'] ?? ''));
                $bankName = trim($_POST['bank_name'] ?? '');
                $upiId = trim($_POST['upi_id'] ?? '');

                if (empty($holder) && empty($accNum) && empty($upiId)) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'Please enter either Bank Account details or a UPI ID.']);
                    exit();
                }

                if ($existing) {
                    $uStmt = $pdo->prepare("
                        UPDATE `provider_bank_accounts`
                        SET account_holder_name = ?, account_number = ?, ifsc_code = ?, bank_name = ?, upi_id = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $uStmt->execute([$holder, $accNum, $ifsc, $bankName, $upiId, $existing['id']]);
                } else {
                    $iStmt = $pdo->prepare("
                        INSERT INTO `provider_bank_accounts`
                        (provider_id, account_holder_name, account_number, ifsc_code, bank_name, upi_id, is_verified, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                    ");
                    $iStmt->execute([$provider_id, $holder, $accNum, $ifsc, $bankName, $upiId]);
                }

                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Settlement details saved successfully!']);
                exit();
            }
        }

        if ($action === 'request_payout') {
            $provider_id = (int)$dealer_id;
            $amount = (float)($_POST['amount'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if ($amount < 100) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Minimum withdrawal amount is â‚¹100.00.']);
                exit();
            }

            $bStmt = $pdo->prepare("SELECT id, account_number, upi_id FROM `provider_bank_accounts` WHERE provider_id = ? ORDER BY id DESC LIMIT 1");
            $bStmt->execute([$provider_id]);
            $bank = $bStmt->fetch(PDO::FETCH_ASSOC);

            if (!$bank || (empty($bank['account_number']) && empty($bank['upi_id']))) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Please add and verify your Bank Account or UPI ID before requesting a payout.']);
                exit();
            }

            $wStmt = $pdo->prepare("SELECT available_balance FROM `provider_wallets` WHERE provider_id = ? LIMIT 1");
            $wStmt->execute([$provider_id]);
            $currBal = (float)($wStmt->fetchColumn() ?: 0);

            if ($currBal < $amount) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Insufficient available balance. You have â‚¹' . number_format($currBal, 2) . ' available.']);
                exit();
            }

            $payoutRef = 'PO-' . strtoupper(bin2hex(random_bytes(5)));
            try {
                $pdo->beginTransaction();

                $deductStmt = $pdo->prepare("
                    UPDATE `provider_wallets`
                    SET available_balance = available_balance - ?,
                        total_withdrawn = total_withdrawn + ?
                    WHERE provider_id = ? AND available_balance >= ?
                ");
                $deductStmt->execute([$amount, $amount, $provider_id, $amount]);

                if ($deductStmt->rowCount() === 0) {
                    $pdo->rollBack();
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'Failed to reserve funds. Please try again.']);
                    exit();
                }

                $insPayout = $pdo->prepare("
                    INSERT INTO `payout_requests`
                    (request_ref, provider_id, amount, status, admin_notes, created_at)
                    VALUES (?, ?, ?, 'PENDING', ?, NOW())
                ");
                $insPayout->execute([$payoutRef, $provider_id, $amount, $notes ?: 'Provider withdrawal request via wallet']);

                $pdo->commit();

                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Payout request of â‚¹' . number_format($amount, 2) . ' submitted successfully! Ref: ' . $payoutRef,
                    'payout_ref' => $payoutRef,
                    'new_available' => $currBal - $amount
                ]);
                exit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Payout request error: ' . $e->getMessage()]);
                exit();
            }
        }

        if ($action === 'delete_branch') {
            $branch_id = (int)($_POST['branch_id'] ?? 0);
            $pdo->prepare("
                DELETE b FROM `serveasy-provider-branches` b
                WHERE b.id = ? AND b.provider_id = ?
            ")->execute([$branch_id, $dealer_id]);

            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Branch deleted.']);
            exit();
        }
    }

    // Fetch active platform fee / convenience fee configuration from serveasy-admin for provider views
    $platformFeePercent = 10.0;
    $platformFeeType = 'percentage';
    $platformFeeAmount = 10.0;
    try {
        $feeSettingStmt = $pdo->query("
            SELECT fee_amount, fee_type, is_active 
            FROM `platform_settings` 
            WHERE setting_key = 'convenience_fee' AND country_code = 'IN' 
            LIMIT 1
        ");
        $feeRow = $feeSettingStmt ? $feeSettingStmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($feeRow && !empty($feeRow['is_active'])) {
            $platformFeeType = $feeRow['fee_type'] ?? 'percentage';
            $platformFeeAmount = (float)($feeRow['fee_amount'] ?? 10.0);
            if ($platformFeeType === 'percentage') {
                $platformFeePercent = $platformFeeAmount;
            }
        }
    } catch (\Throwable $t) {}

    $categories = [];
    try {
        $categories = $pdo->query("SELECT id, name as category_name FROM `serveasy-subcategories` ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $t) {}

    $provider_branches = [];
    try {
        $c_chk = $pdo->prepare("SELECT id, company_name FROM `serveasy-company` WHERE provider_id = ? LIMIT 1");
        $c_chk->execute([$dealer_id]);
        $comp_row = $c_chk->fetch(PDO::FETCH_ASSOC);
        if ($comp_row && !empty(trim($comp_row['company_name'] ?? ''))) {
            $b_chk = $pdo->prepare("SELECT id, branch_name FROM `serveasy-provider-branches` WHERE provider_id = ? ORDER BY id ASC");
            $b_chk->execute([$dealer_id]);
            $provider_branches = $b_chk->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (\Throwable $t) {}

    // Default / init_dashboard response for standalone Provider frontend
    echo json_encode([
        'status'              => 'success',
        'dealer'              => [
            'id'          => $dealer_id,
            'provider_id' => $dealer_id,
            'name'        => !empty($prov_record['full_name']) ? $prov_record['full_name'] : ($dealer['name'] ?? 'Service Provider'),
            'email'       => !empty($prov_record['email']) ? $prov_record['email'] : ($dealer['email'] ?? ''),
            'phone'       => !empty($prov_record['phone']) ? $prov_record['phone'] : ($dealer['phone'] ?? ''),
            'role'        => 'provider',
        ],
        'profile_progress'    => $profile_progress,
        'ver_record'          => $ver_record,
        'prov_record'         => $prov_record,
        'verification_status' => $verification_status,
        'created_at'          => $created_at,
        'platformFeePercent'  => $platformFeePercent,
        'platformFeeType'     => $platformFeeType,
        'platformFeeAmount'   => $platformFeeAmount,
        'categories'          => $categories,
        'provider_branches'   => $provider_branches,
    ]);
    exit();

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Provider API error: ' . $e->getMessage()]);
    exit();
}