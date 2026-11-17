<?php

namespace App\Controllers;

use App\Repositories\ChatRepository;
use App\Helpers\Response;
use App\Helpers\Auth;
use Exception;

class ChatController {
    private ChatRepository $chatRepo;

    public function __construct() {
        $this->chatRepo = new ChatRepository();
    }

    public function index(array $request): void {
        try {
            $chats = $this->chatRepo->getUserChats(Auth::id());
            Response::success($chats, "Chats list retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function messages(array $request): void {
        if (empty($request['chat_id'])) {
            Response::error("Chat ID is required", 422);
        }

        try {
            $page = isset($request['page']) ? (int)$request['page'] : 1;
            $limit = isset($request['limit']) ? (int)$request['limit'] : 50;
            $offset = ($page - 1) * $limit;

            $msgs = $this->chatRepo->getMessagesByChatId((int)$request['chat_id'], $limit, $offset);
            Response::success($msgs, "Messages list retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function send(array $request): void {
        if (empty($request['chat_id']) || (empty($request['message']) && empty($request['media_url']))) {
            Response::error("Chat ID and message content are required", 422);
        }

        try {
            $data = [
                'chat_id' => (int)$request['chat_id'],
                'sender_id' => Auth::id(),
                'message' => $request['message'] ?? null,
                'media_url' => $request['media_url'] ?? null
            ];

            $msgId = $this->chatRepo->createMessage($data);
            Response::success(['message_id' => $msgId], "Message sent successfully", 21);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
