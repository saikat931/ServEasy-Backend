<?php

namespace App\Repositories;

use App\Helpers\DB;
use PDO;

class BookingRepository {
    public function findBookingById(int $id): ?array {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT b.*, ST_AsText(b.location) AS location, ST_X(b.location) AS latitude, ST_Y(b.location) AS longitude,
            s.name as service_name, s.base_price as service_price, pkg.name as package_name, pkg.price as package_price,
            COALESCE(comp.company_name, p.full_name) as provider_name,
            COALESCE(cust.full_name, 'Verified Customer') as customer_first_name, '' as customer_last_name, cust.phone_number as customer_phone,
            r.rating as review_rating, r.comment as review_comment
            FROM vehicle_wash b
            LEFT JOIN `serveasy-services` s ON b.service_id = s.id
            LEFT JOIN `serveasy-service_packages` pkg ON b.package_id = pkg.id
            LEFT JOIN `serveasy-providers` p ON b.provider_id = p.id
            LEFT JOIN `serveasy-company` comp ON comp.provider_id = p.id
            LEFT JOIN `site_user` cust ON b.customer_id = cust.id
            LEFT JOIN reviews r ON r.booking_id = b.id
            WHERE b.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $booking = $stmt->fetch();
        if (!$booking) return null;

        if (isset($booking['location'])) {
            $lat = (float)($booking['latitude'] ?? 0);
            $lng = (float)($booking['longitude'] ?? 0);
            $booking['location'] = ($lat != 0.0 || $lng != 0.0) ? "POINT({$lat} {$lng})" : null;
        }

        $lat = (float)($booking['latitude'] ?? 0);
        $lng = (float)($booking['longitude'] ?? 0);
        if ($lat == 0.0 && $lng == 0.0 && !empty($booking['customer_id'])) {
            try {
                $addrStmt = $db->prepare("SELECT latitude, longitude FROM `user_addresses` WHERE user_id = :uid AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0 ORDER BY is_default DESC, updated_at DESC LIMIT 1");
                $addrStmt->execute([':uid' => (int)$booking['customer_id']]);
                $cAddr = $addrStmt->fetch();
                if ($cAddr && !empty($cAddr['latitude'])) {
                    $booking['latitude'] = (float)$cAddr['latitude'];
                    $booking['longitude'] = (float)$cAddr['longitude'];
                }
            } catch (\Throwable $t) {}
        }

        // Fetch status history
        $stmtHistory = $db->prepare("
            SELECT h.*, u.full_name as first_name, '' as last_name 
            FROM booking_status_history h
            LEFT JOIN `site_user` u ON h.changed_by = u.id
            WHERE h.booking_id = :booking_id 
            ORDER BY h.id ASC
        ");
        $stmtHistory->execute(['booking_id' => $id]);
        $booking['status_history'] = $stmtHistory->fetchAll();

        return $booking;
    }

    public function findBookingByNumber(string $bookingNumber): ?array {
        $stmt = DB::getConnection()->prepare("SELECT id FROM vehicle_wash WHERE booking_number = :num");
        $stmt->execute(['num' => $bookingNumber]);
        $res = $stmt->fetch();
        return $res ? $this->findBookingById((int)$res['id']) : null;
    }

    public function createBooking(array $data): int {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            INSERT INTO vehicle_wash (
                booking_number, customer_id, service_id, package_id, provider_id, 
                schedule_date, schedule_time, address, location, total_amount, 
                status, notes, vehicle_type, vehicle_brand, vehicle_model, 
                vehicle_reg_no, vehicle_color, location_type, landmark, 
                pincode, extras, payment_method, payment_status
            ) 
            VALUES (
                :booking_number, :customer_id, :service_id, :package_id, :provider_id, 
                :schedule_date, :schedule_time, :address, ST_GeomFromText(:point), :total_amount, 
                :status, :notes, :vehicle_type, :vehicle_brand, :vehicle_model, 
                :vehicle_reg_no, :vehicle_color, :location_type, :landmark, 
                :pincode, :extras, :payment_method, :payment_status
            )
        ");
        $lat = !empty($data['latitude']) ? (float)$data['latitude'] : (!empty($data['lat']) ? (float)$data['lat'] : null);
        $lng = !empty($data['longitude']) ? (float)$data['longitude'] : (!empty($data['lng']) ? (float)$data['lng'] : null);

        // Fallback to customer's saved address in user_addresses if missing from payload or using default placeholder
        $isGenericDefault = ($lat !== null && abs($lat - 22.6231) < 0.0001 && $lng !== null && abs($lng - 88.4348) < 0.0001);
        if (($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0) || $isGenericDefault) && !empty($data['customer_id'])) {
            try {
                $addrStmt = $db->prepare("
                    SELECT latitude, longitude FROM `user_addresses`
                    WHERE user_id = :uid AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0 AND longitude != 0
                    ORDER BY is_default DESC, updated_at DESC
                    LIMIT 1
                ");
                $addrStmt->execute([':uid' => (int)$data['customer_id']]);
                $savedAddr = $addrStmt->fetch();
                if ($savedAddr && !empty($savedAddr['latitude'])) {
                    $lat = (float)$savedAddr['latitude'];
                    $lng = (float)$savedAddr['longitude'];
                }
            } catch (\Throwable $t) {}
        }

        $point = "POINT(" . ($lat ?? 0.0) . " " . ($lng ?? 0.0) . ")";
        $stmt->execute([
            'booking_number' => $data['booking_number'] ?? ('SN-' . strtoupper(uniqid())),
            'customer_id' => $data['customer_id'],
            'service_id' => $data['service_id'],
            'package_id' => $data['package_id'] ?? null,
            'provider_id' => $data['provider_id'],
            'schedule_date' => $data['schedule_date'],
            'schedule_time' => $data['schedule_time'],
            'address' => $data['address'],
            'point' => $point,
            'total_amount' => $data['total_amount'],
            'status' => $data['status'] ?? 'PENDING',
            'notes' => $data['notes'] ?? null,
            'vehicle_type' => $data['vehicle_type'] ?? null,
            'vehicle_brand' => $data['vehicle_brand'] ?? null,
            'vehicle_model' => $data['vehicle_model'] ?? null,
            'vehicle_reg_no' => $data['vehicle_reg_no'] ?? null,
            'vehicle_color' => $data['vehicle_color'] ?? null,
            'location_type' => $data['location_type'] ?? 'doorstep',
            'landmark' => $data['landmark'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'extras' => $data['extras'] ?? null,
            'payment_method' => !empty($data['payment_method']) ? $data['payment_method'] : 'Pay on Completion',
            'payment_status' => !empty($data['payment_status']) ? $data['payment_status'] : 'UNPAID'
        ]);
        return (int)$db->lastInsertId();
    }

    public function updateStatus(int $id, string $status): bool {
        $db = DB::getConnection();
        $targetStatus = strtolower(trim($status));
        if ($targetStatus === 'in_progress') {
            $stmt = $db->prepare("
                UPDATE vehicle_wash 
                SET status = :status, 
                    started_at = COALESCE(started_at, NOW()) 
                WHERE id = :id
            ");
            return $stmt->execute(['id' => $id, 'status' => $targetStatus]);
        } elseif ($targetStatus === 'completed') {
            $stmt = $db->prepare("
                UPDATE vehicle_wash 
                SET status = :status, 
                    completed_at = COALESCE(completed_at, NOW())
                WHERE id = :id
            ");
            return $stmt->execute(['id' => $id, 'status' => $targetStatus]);
        } else {
            $stmt = $db->prepare("UPDATE vehicle_wash SET status = :status WHERE id = :id");
            return $stmt->execute(['id' => $id, 'status' => $targetStatus]);
        }
    }

    public function updatePaymentInfo(int $id, string $method, string $status): bool {
        $stmt = DB::getConnection()->prepare("UPDATE vehicle_wash SET payment_method = :method, payment_status = :status WHERE id = :id");
        return $stmt->execute([
            'id' => $id,
            'method' => $method,
            'status' => $status
        ]);
    }

    public function updateBookingDetails(int $id, array $data): bool {
        $db = DB::getConnection();
        $fields = [];
        $params = ['id' => $id];

        $allowedColumns = [
            'schedule_date',
            'schedule_time',
            'address',
            'notes',
            'vehicle_brand',
            'vehicle_model',
            'vehicle_reg_no',
            'vehicle_type',
            'vehicle_color',
            'status'
        ];

        foreach ($allowedColumns as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "`{$col}` = :{$col}";
                $params[$col] = $data[$col];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE vehicle_wash SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    }

    public function addStatusHistory(int $bookingId, string $status, ?string $remarks, int $changedBy): bool {
        $stmt = DB::getConnection()->prepare("
            INSERT INTO booking_status_history (booking_id, status, remarks, changed_by) 
            VALUES (:booking_id, :status, :remarks, :changed_by)
        ");
        return $stmt->execute([
            'booking_id' => $bookingId,
            'status' => $status,
            'remarks' => $remarks,
            'changed_by' => $changedBy
        ]);
    }

    public function getBookingsByCustomer(int $customerId): array {
        $stmt = DB::getConnection()->prepare("
            SELECT b.*, ST_AsText(b.location) AS location, ST_X(b.location) AS latitude, ST_Y(b.location) AS longitude,
                   s.name as service_name, s.base_price as service_price, pkg.name as package_name, pkg.price as package_price,
                   COALESCE(c.company_name, p.full_name) as provider_name,
                   r.rating as review_rating, r.comment as review_comment
            FROM vehicle_wash b
            LEFT JOIN `serveasy-services` s ON b.service_id = s.id
            LEFT JOIN `serveasy-service_packages` pkg ON b.package_id = pkg.id
            LEFT JOIN `serveasy-providers` p ON b.provider_id = p.id
            LEFT JOIN `serveasy-company` c ON c.provider_id = p.id
            LEFT JOIN reviews r ON r.booking_id = b.id
            WHERE b.customer_id = :customer_id 
            ORDER BY b.id DESC
        ");
        $stmt->execute(['customer_id' => $customerId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (isset($row['location'])) {
                $lat = (float)($row['latitude'] ?? 0);
                $lng = (float)($row['longitude'] ?? 0);
                $row['location'] = ($lat != 0.0 || $lng != 0.0) ? "POINT({$lat} {$lng})" : null;
            }
        }
        return $rows;
    }

    public function getBookingsByProvider(int $providerId): array {
        $stmt = DB::getConnection()->prepare("
            SELECT b.*, ST_AsText(b.location) AS location, ST_X(b.location) AS latitude, ST_Y(b.location) AS longitude,
                   s.name as service_name, s.base_price as service_price, pkg.name as package_name, pkg.price as package_price,
                   COALESCE(u.full_name, 'Customer') as customer_first_name, '' as customer_last_name,
                   u.phone_number as customer_phone
            FROM vehicle_wash b
            LEFT JOIN `serveasy-services` s ON b.service_id = s.id
            LEFT JOIN `serveasy-service_packages` pkg ON b.package_id = pkg.id
            LEFT JOIN site_user u ON b.customer_id = u.id
            WHERE b.provider_id = :provider_id 
            ORDER BY b.id DESC
        ");
        $stmt->execute(['provider_id' => $providerId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (isset($row['location'])) {
                $lat = (float)($row['latitude'] ?? 0);
                $lng = (float)($row['longitude'] ?? 0);
                $row['location'] = ($lat != 0.0 || $lng != 0.0) ? "POINT({$lat} {$lng})" : null;
            }
        }
        return $rows;
    }

    public function getAllBookings(int $page = 1, int $limit = 20): array {
        $offset = ($page - 1) * $limit;
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT b.id, b.booking_number, b.schedule_date, b.schedule_time, b.total_amount, b.status, b.started_at, b.completed_at, b.created_at,
            s.name as service_name, COALESCE(comp.company_name, p.full_name) as provider_name,
            COALESCE(cust.full_name, 'Customer') as customer_first_name, '' as customer_last_name
            FROM vehicle_wash b
            LEFT JOIN `serveasy-services` s ON b.service_id = s.id
            LEFT JOIN `serveasy-providers` p ON b.provider_id = p.id
            LEFT JOIN `serveasy-company` comp ON comp.provider_id = p.id
            LEFT JOIN `site_user` cust ON b.customer_id = cust.id
            ORDER BY b.id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Check if a provider is a single man / individual provider (not a registered company)
     */
    public function isSingleProvider(int $providerId): bool {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT id, company_name
            FROM `serveasy-company`
            WHERE provider_id = :id
              AND company_name IS NOT NULL
              AND TRIM(company_name) != ''
            LIMIT 1
        ");
        $stmt->execute(['id' => $providerId]);
        $company = $stmt->fetch();
        // If registered company exists with non-empty company name, they are NOT a single provider
        return empty($company);
    }

    /**
     * Get booked slots for a provider on a specific date (only blocked for single man providers)
     */
    public function getProviderBookedSlots(int $providerId, string $date, bool $forUpdate = false): array {
        $isSingle = $this->isSingleProvider($providerId);
        if (!$isSingle) {
            return [
                'is_single_provider' => false,
                'booked_slots' => [],
                'booked_times' => []
            ];
        }

        $db = DB::getConnection();
        $sql = "
            SELECT schedule_time, status
            FROM vehicle_wash
            WHERE provider_id = :provider_id
              AND schedule_date = :schedule_date
              AND LOWER(status) NOT IN ('cancelled', 'rejected')
        " . ($forUpdate ? " FOR UPDATE" : "");
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'provider_id' => $providerId,
            'schedule_date' => $date
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $slotsMap = [
            '09:00 AM – 10:00 AM' => 9,
            '10:00 AM – 11:00 AM' => 10,
            '11:00 AM – 12:00 PM' => 11,
            '01:00 PM – 02:00 PM' => 13,
            '02:00 PM – 03:00 PM' => 14,
            '03:00 PM – 04:00 PM' => 15
        ];

        $bookedSlots = [];
        $bookedTimes = [];

        foreach ($rows as $row) {
            $rawTime = trim($row['schedule_time'] ?? '');
            if ($rawTime === '') continue;
            $bookedTimes[] = $rawTime;

            $matchedSlot = null;
            foreach ($slotsMap as $fullSlot => $h) {
                if (strcasecmp($rawTime, $fullSlot) === 0) {
                    $matchedSlot = $fullSlot;
                    break;
                }
            }

            if (!$matchedSlot) {
                $ts = strtotime($rawTime);
                if ($ts !== false) {
                    $hour = (int)date('G', $ts);
                    foreach ($slotsMap as $fullSlot => $h) {
                        if ($h === $hour) {
                            $matchedSlot = $fullSlot;
                            break;
                        }
                    }
                }
            }

            if ($matchedSlot && !in_array($matchedSlot, $bookedSlots, true)) {
                $bookedSlots[] = $matchedSlot;
            }
        }

        return [
            'is_single_provider' => true,
            'booked_slots' => $bookedSlots,
            'booked_times' => array_values(array_unique($bookedTimes))
        ];
    }

    /**
     * Check if a specific slot is available for a provider on a specific date
     */
    public function isSlotAvailable(int $providerId, string $date, string $timeSlot, bool $forUpdate = false): bool {
        if (!$this->isSingleProvider($providerId)) {
            return true;
        }
        $info = $this->getProviderBookedSlots($providerId, $date, $forUpdate);
        $timeStr = trim($timeSlot);
        if (in_array($timeStr, $info['booked_slots'], true) || in_array($timeStr, $info['booked_times'], true)) {
            return false;
        }
        $ts = strtotime($timeStr);
        if ($ts !== false) {
            $hour = (int)date('G', $ts);
            $slotsMap = [
                '09:00 AM – 10:00 AM' => 9,
                '10:00 AM – 11:00 AM' => 10,
                '11:00 AM – 12:00 PM' => 11,
                '01:00 PM – 02:00 PM' => 13,
                '02:00 PM – 03:00 PM' => 14,
                '03:00 PM – 04:00 PM' => 15
            ];
            foreach ($slotsMap as $fullSlot => $h) {
                if ($h === $hour && in_array($fullSlot, $info['booked_slots'], true)) {
                    return false;
                }
            }
        }
        return true;
    }
}
