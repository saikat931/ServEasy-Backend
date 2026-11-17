<?php

namespace App\Services;

use App\Repositories\ProviderRepository;
use App\Repositories\UserRepository;
use App\Helpers\DB;
use Exception;

class ProviderService {
    private ProviderRepository $providerRepo;
    private UserRepository $userRepo;

    public function __construct() {
        $this->providerRepo = new ProviderRepository();
        $this->userRepo = new UserRepository();
    }

    public function submitKyc(int $userId, array $data): bool {
        $provider = $this->providerRepo->findProviderByUserId($userId);
        if (!$provider) {
            // Create provider profile
            $providerId = $this->providerRepo->createProvider([
                'user_id' => $userId,
                'business_name' => $data['business_name'],
                'description' => $data['description'] ?? null,
                'experience_years' => $data['experience_years'] ?? 0,
                'latitude' => $data['latitude'] ?? 0.0,
                'longitude' => $data['longitude'] ?? 0.0
            ]);
        } else {
            $providerId = (int)$provider['id'];
        }

        // Upload documents (e.g. Aadhaar and PAN details)
        if (!empty($data['documents'])) {
            foreach ($data['documents'] as $doc) {
                $this->providerRepo->addDocument([
                    'provider_id' => $providerId,
                    'doc_type' => $doc['doc_type'],
                    'doc_number' => $doc['doc_number'],
                    'file_url' => $doc['file_url']
                ]);
            }
        }

        // Set status to pending
        $this->providerRepo->updateKycStatus($providerId, 'pending');
        return true;
    }

    public function verifyKyc(int $providerId, string $status, ?string $rejectionReason = null): bool {
        if (!in_array($status, ['approved', 'rejected'])) {
            throw new Exception("Invalid KYC verification status");
        }

        DB::beginTransaction();
        try {
            $this->providerRepo->updateKycStatus($providerId, $status);
            
            $documents = $this->providerRepo->getDocuments($providerId);
            foreach ($documents as $doc) {
                $this->providerRepo->updateDocumentStatus((int)$doc['id'], $status, $rejectionReason);
            }

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function requestWithdrawal(int $userId, float $amount, array $bankDetails): bool {
        $provider = $this->providerRepo->findProviderByUserId($userId);
        if (!$provider) {
            throw new Exception("Provider profile not found");
        }

        $analytics = $this->providerRepo->getEarningsAndWithdrawals((int)$provider['id']);
        if ($analytics['balance'] < $amount) {
            throw new Exception("Insufficient payout balance. Available: {$analytics['balance']}");
        }

        $db = DB::getConnection();
        $stmt = $db->prepare("
            INSERT INTO withdrawals (provider_id, amount, status, bank_details) 
            VALUES (:provider_id, :amount, 'pending', :bank_details)
        ");
        return $stmt->execute([
            'provider_id' => $provider['id'],
            'amount' => $amount,
            'bank_details' => json_encode($bankDetails)
        ]);
    }

    public function getAnalytics(int $userId): array {
        $provider = $this->providerRepo->findProviderByUserId($userId);
        if (!$provider) {
            throw new Exception("Provider profile not found");
        }

        $pId = (int)$provider['id'];
        $stats = $this->providerRepo->getEarningsAndWithdrawals($pId);

        // Fetch monthly completed earnings data for charts
        $db = DB::getConnection();
        $stmtChart = $db->prepare("
            SELECT DATE_FORMAT(b.schedule_date, '%Y-%m') as month, SUM(p.amount) as earnings, COUNT(b.id) as jobs
            FROM vehicle_wash b
            JOIN payments p ON b.id = p.booking_id AND p.status = 'completed'
            WHERE b.provider_id = :provider_id AND b.status = 'completed'
            GROUP BY month
            ORDER BY month ASC
            LIMIT 12
        ");
        $stmtChart->execute(['provider_id' => $pId]);
        $chartData = $stmtChart->fetchAll();

        return [
            'summary' => $stats,
            'charts' => $chartData,
            'business' => [
                'name' => $provider['business_name'],
                'rating' => $provider['rating'],
                'is_available' => (bool)$provider['is_available']
            ]
        ];
    }
}
