<?php

namespace App\Repositories;

use App\Helpers\DB;
use PDO;

class ProviderRepository {
    public function findProviderById(int $id): ?array {
        $stmt = DB::getConnection()->prepare("
            SELECT p.*, p.is_active AS is_available, p.verified_status AS kyc_status, p.id AS user_id,
            p.full_name as first_name, '' as last_name, p.email as email, p.phone as phone
            FROM `serveasy-providers` p
            WHERE p.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $provider = $stmt->fetch();
        return $provider ?: null;
    }

    public function findProviderByUserId(int $userId): ?array {
        $stmt = DB::getConnection()->prepare("
            SELECT p.*, p.is_active AS is_available, p.verified_status AS kyc_status, p.id AS user_id
            FROM `serveasy-providers` p
            WHERE p.id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $provider = $stmt->fetch();
        return $provider ?: null;
    }

    public function createProvider(array $data): int {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            INSERT INTO providers (user_id, business_name, description, kyc_status, location, experience_years, is_available) 
            VALUES (:user_id, :business_name, :description, 'pending', ST_GeomFromText(:point), :experience_years, 1)
        ");
        $point = "POINT(" . ($data['latitude'] ?? 0.0) . " " . ($data['longitude'] ?? 0.0) . ")";
        $stmt->execute([
            'user_id' => $data['user_id'],
            'business_name' => $data['business_name'],
            'description' => $data['description'] ?? null,
            'point' => $point,
            'experience_years' => $data['experience_years'] ?? 0
        ]);
        return (int)$db->lastInsertId();
    }

    public function updateKycStatus(int $id, string $status): bool {
        $stmt = DB::getConnection()->prepare("UPDATE providers SET kyc_status = :status WHERE id = :id");
        return $stmt->execute([
            'id' => $id,
            'status' => $status
        ]);
    }

    public function addDocument(array $data): int {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            INSERT INTO provider_documents (provider_id, doc_type, doc_number, file_url, verification_status) 
            VALUES (:provider_id, :doc_type, :doc_number, :file_url, 'pending')
        ");
        $stmt->execute([
            'provider_id' => $data['provider_id'],
            'doc_type' => $data['doc_type'],
            'doc_number' => $data['doc_number'],
            'file_url' => $data['file_url']
        ]);
        return (int)$db->lastInsertId();
    }

    public function getDocuments(int $providerId): array {
        $stmt = DB::getConnection()->prepare("SELECT * FROM provider_documents WHERE provider_id = :provider_id");
        $stmt->execute(['provider_id' => $providerId]);
        return $stmt->fetchAll();
    }

    public function updateDocumentStatus(int $docId, string $status, ?string $reason = null): bool {
        $stmt = DB::getConnection()->prepare("
            UPDATE provider_documents 
            SET verification_status = :status, rejection_reason = :reason, verified_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");
        return $stmt->execute([
            'id' => $docId,
            'status' => $status,
            'reason' => $reason
        ]);
    }

    public function getPendingKycProviders(): array {
        $stmt = DB::getConnection()->prepare("
            SELECT p.id, COALESCE(c.company_name, p.full_name) as business_name, p.verified_status as kyc_status, p.full_name as first_name, '' as last_name, p.email as email 
            FROM `serveasy-providers` p
            LEFT JOIN `serveasy-company` c ON c.provider_id = p.id
            WHERE p.verified_status = 'pending'
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findNearby(float $lat, float $lng, float $radiusKm = 10.0, ?int $subcategoryId = null): array {
        $query = "
            SELECT p.id, COALESCE(c.company_name, p.full_name) as business_name, 4.5 as rating, p.latitude, p.longitude,
            (ST_Distance_Sphere(POINT(p.latitude, p.longitude), ST_GeomFromText(:point)) / 1000) AS distance_km
            FROM `serveasy-providers` p
            LEFT JOIN `serveasy-company` c ON c.provider_id = p.id
        ";

        if ($subcategoryId) {
            $query .= " JOIN `serveasy-services` s ON s.provider_id = p.id AND s.subcategory_id = :subcategory_id AND s.is_active = 1 ";
        }

        $query .= " 
            WHERE p.verified_status = 'approved' AND p.is_active = 1
            HAVING distance_km <= :radius
            ORDER BY distance_km ASC
        ";

        $stmt = DB::getConnection()->prepare($query);
        $point = "POINT({$lat} {$lng})";
        $stmt->bindValue(':point', $point);
        $stmt->bindValue(':radius', $radiusKm);
        if ($subcategoryId) {
            $stmt->bindValue(':subcategory_id', $subcategoryId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function saveAvailability(int $providerId, array $slots): bool {
        $db = DB::getConnection();
        
        // Remove existing slots first
        $stmtDelete = $db->prepare("DELETE FROM `serveasy-service_availability` WHERE provider_id = :provider_id");
        $stmtDelete->execute(['provider_id' => $providerId]);

        $stmtInsert = $db->prepare("
            INSERT INTO `serveasy-service_availability` (provider_id, day_of_week, start_time, end_time, is_active) 
            VALUES (:provider_id, :day_of_week, :start_time, :end_time, 1)
        ");

        foreach ($slots as $slot) {
            $stmtInsert->execute([
                'provider_id' => $providerId,
                'day_of_week' => $slot['day_of_week'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time']
            ]);
        }

        return true;
    }

    public function getAvailability(int $providerId): array {
        $stmt = DB::getConnection()->prepare("SELECT * FROM `serveasy-service_availability` WHERE provider_id = :provider_id AND is_active = 1");
        $stmt->execute(['provider_id' => $providerId]);
        return $stmt->fetchAll();
    }

    public function getEarningsAndWithdrawals(int $providerId): array {
        $db = DB::getConnection();
        
        // Net completed earnings
        $stmtEarn = $db->prepare("
            SELECT SUM(p.amount) as total_earned, COUNT(b.id) as bookings_count
            FROM vehicle_wash b
            JOIN payments p ON b.id = p.booking_id AND p.status = 'completed'
            WHERE b.provider_id = :provider_id AND b.status = 'completed'
        ");
        $stmtEarn->execute(['provider_id' => $providerId]);
        $earning = $stmtEarn->fetch();

        // Total withdrawn
        $stmtWith = $db->prepare("
            SELECT SUM(amount) as total_withdrawn 
            FROM withdrawals 
            WHERE provider_id = :provider_id AND status = 'approved'
        ");
        $stmtWith->execute(['provider_id' => $providerId]);
        $withdrawn = $stmtWith->fetch();

        return [
            'total_earned' => (float)($earning['total_earned'] ?? 0.0),
            'bookings_count' => (int)($earning['bookings_count'] ?? 0),
            'total_withdrawn' => (float)($withdrawn['total_withdrawn'] ?? 0.0),
            'balance' => (float)($earning['total_earned'] ?? 0.0) - (float)($withdrawn['total_withdrawn'] ?? 0.0)
        ];
    }
}
