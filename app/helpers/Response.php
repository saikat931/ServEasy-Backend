<?php

namespace App\Helpers;

class Response {
    public static function json(mixed $data, int $status = 200, array $headers = []): void {
        header("Content-Type: application/json; charset=UTF-8");
        foreach ($headers as $key => $value) {
            header("{$key}: {$value}");
        }
        
        http_response_code($status);
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            $encoded = json_encode([
                'status' => 'error',
                'success' => false,
                'message' => 'Encoding error: ' . json_last_error_msg(),
                'data' => null
            ]);
        }
        echo $encoded;
        exit;
    }

    public static function success(mixed $data = null, string $message = 'Success', int $status = 200): void {
        self::json([
            'status' => 'success',
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $status);
    }

    public static function error(string $message = 'An error occurred', int $status = 400, mixed $errors = null): void {
        self::json([
            'status' => 'error',
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ], $status);
    }
}
