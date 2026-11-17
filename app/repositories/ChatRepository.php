<?php

namespace App\Repositories;

use App\Helpers\DB;
use PDO;

class ChatRepository {
    public function findOrCreateChat(?int $bookingId = null): int {
        $db = DB::getConnection();
        if ($bookingId) {
            $stmt = $db->prepare("SELECT id FROM chats WHERE booking_id = :booking_id");
            $stmt->execute(['booking_id' => $bookingId]);
            $chat = $stmt->fetch();
            if ($chat) {
                return (int)$chat['id'];
            }
        }

        $stmtInsert = $db->prepare("INSERT INTO chats (booking_id) VALUES (:booking_id)");
        $stmtInsert->execute(['booking_id' => $bookingId]);
        return (int)$db->lastInsertId();
    }

    public function createMessage(array $data): int {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            INSERT INTO chat_messages (chat_id, sender_id, message, media_url, is_read) 
            VALUES (:chat_id, :sender_id, :message, :media_url, 0)
        ");
        $stmt->execute([
            'chat_id' => $data['chat_id'],
            'sender_id' => $data['sender_id'],
            'message' => $data['message'] ?? null,
            'media_url' => $data['media_url'] ?? null
        ]);
        return (int)$db->lastInsertId();
    }

    public function getMessagesByChatId(int $chatId, int $limit = 50, int $offset = 0): array {
        $stmt = DB::getConnection()->prepare("
            SELECT m.*, u.full_name as first_name, '' as last_name, NULL as avatar_url 
            FROM chat_messages m
            LEFT JOIN site_user u ON m.sender_id = u.id
            WHERE m.chat_id = :chat_id 
            ORDER BY m.id DESC 
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        // Reverse to show in chronological order
        return array_reverse($stmt->fetchAll());
    }

    public function getUserChats(int $userId): array {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT DISTINCT c.id as chat_id, c.booking_id, b.booking_number,
            (SELECT message FROM chat_messages WHERE chat_id = c.id ORDER BY id DESC LIMIT 1) as last_message,
            (SELECT created_at FROM chat_messages WHERE chat_id = c.id ORDER BY id DESC LIMIT 1) as last_message_time
            FROM chats c
            LEFT JOIN vehicle_wash b ON c.booking_id = b.id
            WHERE b.customer_id = :user_id 
               OR b.provider_id = (SELECT id FROM providers WHERE user_id = :user_id)
            ORDER BY last_message_time DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }
}
