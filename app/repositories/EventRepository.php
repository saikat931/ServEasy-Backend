<?php

namespace App\Repositories;

use App\Helpers\DB;
use PDO;

class EventRepository {
    private static bool $tablesChecked = false;

    public function __construct() {
        if (!self::$tablesChecked) {
            try {
                $db = DB::getConnection();
                if (!$db->inTransaction()) {
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS `booking_events` (
                            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            `event_name` VARCHAR(64) NOT NULL,
                            `booking_id` INT UNSIGNED NOT NULL,
                            `recipient_type` ENUM('CUSTOMER', 'PROVIDER') NOT NULL,
                            `recipient_id` INT UNSIGNED NOT NULL,
                            `payload` JSON NOT NULL,
                            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            INDEX `idx_recipient_event` (`recipient_type`, `recipient_id`, `id`),
                            INDEX `idx_booking_id` (`booking_id`),
                            INDEX `idx_created_at` (`created_at`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    self::$tablesChecked = true;
                }
            } catch (\Throwable $t) {
                error_log("EventRepository init warning: " . $t->getMessage());
            }
        }
    }

    /**
     * Record an event in the transactional outbox table
     */
    public function recordEvent(string $eventName, int $bookingId, string $recipientType, int $recipientId, array $payload): int {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            INSERT INTO `booking_events` 
                (`event_name`, `booking_id`, `recipient_type`, `recipient_id`, `payload`, `created_at`) 
            VALUES 
                (:event_name, :booking_id, :recipient_type, :recipient_id, :payload, NOW())
        ");
        $stmt->execute([
            ':event_name' => $eventName,
            ':booking_id' => $bookingId,
            ':recipient_type' => strtoupper($recipientType),
            ':recipient_id' => $recipientId,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Retrieve any pending events since $lastEventId for a specific recipient
     */
    public function getEventsForRecipient(string $recipientType, int $recipientId, int $lastEventId, int $limit = 25): array {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT `id`, `event_name`, `booking_id`, `payload`, `created_at`
            FROM `booking_events`
            WHERE `recipient_type` = :recipient_type
              AND `recipient_id` = :recipient_id
              AND `id` > :last_event_id
            ORDER BY `id` ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':recipient_type', strtoupper($recipientType), PDO::PARAM_STR);
        $stmt->bindValue(':recipient_id', (int)$recipientId, PDO::PARAM_INT);
        $stmt->bindValue(':last_event_id', (int)$lastEventId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $events = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row['payload'], true);
            $events[] = [
                'id' => (int)$row['id'],
                'event_name' => $row['event_name'],
                'booking_id' => (int)$row['booking_id'],
                'payload' => is_array($decoded) ? $decoded : [],
                'created_at' => $row['created_at']
            ];
        }
        return $events;
    }

    /**
     * Get the latest event ID for a recipient (used to sync initial pointer)
     */
    public function getLatestEventIdForRecipient(string $recipientType, int $recipientId): int {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT COALESCE(MAX(`id`), 0)
            FROM `booking_events`
            WHERE `recipient_type` = :recipient_type
              AND `recipient_id` = :recipient_id
        ");
        $stmt->execute([
            ':recipient_type' => strtoupper($recipientType),
            ':recipient_id' => $recipientId
        ]);
        return (int)$stmt->fetchColumn();
    }
}
