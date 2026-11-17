<?php

namespace App\Repositories;

use App\Helpers\DB;
use PDO;

class NotificationRepository {
    private static bool $tablesChecked = false;

    public function __construct() {
        if (!self::$tablesChecked) {
            try {
                $db = DB::getConnection();
                if (!$db->inTransaction()) {
                    $db->exec("CREATE TABLE IF NOT EXISTS `notifications` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `user_id` INT NOT NULL,
                        `title` VARCHAR(150) NOT NULL,
                        `message` TEXT NOT NULL,
                        `type` VARCHAR(50) DEFAULT 'general',
                        `is_read` TINYINT(1) DEFAULT 0,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_notif_user` (`user_id`),
                        INDEX `idx_notif_created` (`created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                    $db->exec("CREATE TABLE IF NOT EXISTS `notification_logs` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `notification_id` INT NOT NULL,
                        `channel` VARCHAR(50) NOT NULL,
                        `status` VARCHAR(50) NOT NULL,
                        `error_message` TEXT DEFAULT NULL,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_notif_log_id` (`notification_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    self::$tablesChecked = true;
                }
            } catch (\Throwable $t) {
                error_log("NotificationRepository init warning: " . $t->getMessage());
            }
        }
    }

    public function createNotification(array $data): int {
        try {
            $db = DB::getConnection();
            $stmt = $db->prepare("
                INSERT INTO `notifications` (`user_id`, `title`, `message`, `type`, `is_read`) 
                VALUES (:user_id, :title, :message, :type, 0)
            ");
            $stmt->execute([
                'user_id' => $data['user_id'],
                'title' => $data['title'],
                'message' => $data['message'],
                'type' => $data['type'] ?? 'general'
            ]);
            return (int)$db->lastInsertId();
        } catch (\Throwable $t) {
            error_log("Failed to create notification: " . $t->getMessage());
            return 0;
        }
    }

    public function logNotification(int $notificationId, string $channel, string $status, ?string $error = null): int {
        try {
            $db = DB::getConnection();
            $stmt = $db->prepare("
                INSERT INTO `notification_logs` (`notification_id`, `channel`, `status`, `error_message`) 
                VALUES (:notification_id, :channel, :status, :error_message)
            ");
            $stmt->execute([
                'notification_id' => $notificationId,
                'channel' => $channel,
                'status' => $status,
                'error_message' => $error
            ]);
            return (int)$db->lastInsertId();
        } catch (\Throwable $t) {
            error_log("Failed to log notification: " . $t->getMessage());
            return 0;
        }
    }

    public function getNotificationsByUserId(int $userId, int $limit = 20): array {
        try {
            $stmt = DB::getConnection()->prepare("
                SELECT * FROM `notifications` 
                WHERE `user_id` = :user_id 
                ORDER BY `id` DESC 
                LIMIT :limit
            ");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable $t) {
            error_log("Failed to fetch notifications: " . $t->getMessage());
            return [];
        }
    }

    public function markAsRead(int $id, int $userId): bool {
        try {
            $stmt = DB::getConnection()->prepare("UPDATE `notifications` SET `is_read` = 1 WHERE `id` = :id AND `user_id` = :user_id");
            return $stmt->execute([
                'id' => $id,
                'user_id' => $userId
            ]);
        } catch (\Throwable $t) {
            error_log("Failed to mark notification as read: " . $t->getMessage());
            return false;
        }
    }

    public function markAllAsRead(int $userId): bool {
        try {
            $stmt = DB::getConnection()->prepare("UPDATE `notifications` SET `is_read` = 1 WHERE `user_id` = :user_id");
            return $stmt->execute(['user_id' => $userId]);
        } catch (\Throwable $t) {
            error_log("Failed to mark all notifications as read: " . $t->getMessage());
            return false;
        }
    }

    public function deleteNotification(int $id, int $userId): bool {
        try {
            $stmt = DB::getConnection()->prepare("DELETE FROM `notifications` WHERE `id` = :id AND `user_id` = :user_id");
            return $stmt->execute([
                'id' => $id,
                'user_id' => $userId
            ]);
        } catch (\Throwable $t) {
            error_log("Failed to delete notification: " . $t->getMessage());
            return false;
        }
    }
}
