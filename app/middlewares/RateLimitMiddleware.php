<?php

namespace App\Middlewares;

use App\Helpers\Response;

class RateLimitMiddleware {
    private int $limit = 60; // Max 60 requests
    private int $timeFrame = 60; // Per 60 seconds

    public function handle(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $key = 'rate_' . md5($ip);

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'requests' => 1,
                'start_time' => time()
            ];
            return;
        }

        $data = $_SESSION[$key];
        $elapsed = time() - $data['start_time'];

        if ($elapsed > $this->timeFrame) {
            // Reset window
            $_SESSION[$key] = [
                'requests' => 1,
                'start_time' => time()
            ];
            return;
        }

        if ($data['requests'] >= $this->limit) {
            Response::error("Too many requests. Please try again later.", 429);
        }

        $_SESSION[$key]['requests']++;
    }
}
