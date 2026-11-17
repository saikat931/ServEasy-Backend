<?php

namespace App\Repositories;

use App\Helpers\DB;
use PDO;
use Exception;

class WalletRepository {
    public function findWalletByUserId(int $userId): ?array {
        $stmt = DB::getConnection()->prepare("SELECT * FROM `wallets` WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $wallet = $stmt->fetch();
        return $wallet ?: null;
    }

    public function createWallet(int $userId, string $currency = 'INR'): int {
        $db = DB::getConnection();
        $stmt = $db->prepare("INSERT INTO `wallets` (user_id, balance, currency) VALUES (:user_id, 0.00, :currency)");
        $stmt->execute([
            'user_id' => $userId,
            'currency' => $currency
        ]);
        return (int)$db->lastInsertId();
    }

    public function updateBalance(int $walletId, float $amount, string $type, string $description, ?string $referenceId = null): bool {
        $db = DB::getConnection();
        
        // Fetch current balance to check or update
        $stmt = $db->prepare("SELECT balance FROM `wallets` WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $walletId]);
        $wallet = $stmt->fetch();
        
        if (!$wallet) {
            throw new Exception("Wallet not found");
        }

        $currentBalance = (float)$wallet['balance'];
        
        if ($type === 'credit') {
            $newBalance = $currentBalance + $amount;
        } else {
            if ($currentBalance < $amount) {
                throw new Exception("Insufficient wallet balance");
            }
            $newBalance = $currentBalance - $amount;
        }

        // Update wallet balance
        $stmtUpdate = $db->prepare("UPDATE `wallets` SET balance = :balance WHERE id = :id");
        $stmtUpdate->execute([
            'balance' => $newBalance,
            'id' => $walletId
        ]);

        // Insert transaction record
        $stmtTx = $db->prepare("
            INSERT INTO `wallet_transactions` (wallet_id, amount, type, description, reference_id) 
            VALUES (:wallet_id, :amount, :type, :description, :reference_id)
        ");
        return $stmtTx->execute([
            'wallet_id' => $walletId,
            'amount' => $amount,
            'type' => $type,
            'description' => $description,
            'reference_id' => $referenceId
        ]);
    }

    public function getTransactionsByWalletId(int $walletId, int $limit = 20, int $offset = 0): array {
        $stmt = DB::getConnection()->prepare("
            SELECT * FROM `wallet_transactions` 
            WHERE wallet_id = :wallet_id 
            ORDER BY id DESC 
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
