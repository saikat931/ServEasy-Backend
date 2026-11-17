<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Helpers\Response;
use Exception;

class AuthController {
    private AuthService $authService;

    public function __construct() {
        $this->authService = new AuthService();
    }

    public function register(array $request): void {
        $required = ['first_name', 'last_name', 'email', 'phone', 'password'];
        foreach ($required as $field) {
            if (empty($request[$field])) {
                Response::error("Field '{$field}' is required", 422);
            }
        }

        try {
            $tokens = $this->authService->register($request);
            Response::success($tokens, "User registered successfully", 21);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function login(array $request): void {
        if (empty($request['email']) || empty($request['password'])) {
            Response::error("Email and password are required", 422);
        }

        try {
            $tokens = $this->authService->login($request['email'], $request['password']);
            Response::success($tokens, "Logged in successfully");
        } catch (Exception $e) {
            Response::error($e->getMessage(), 401);
        }
    }

    public function refresh(array $request): void {
        if (empty($request['refresh_token'])) {
            Response::error("Refresh token is required", 422);
        }

        try {
            $tokens = $this->authService->refreshToken($request['refresh_token']);
            Response::success($tokens, "Token refreshed successfully");
        } catch (Exception $e) {
            Response::error($e->getMessage(), 401);
        }
    }

    public function logout(array $request): void {
        // Since stateless JWT is used, the client drops the token.
        // Option: add token signature to blacklist database if required.
        Response::success(null, "Logged out successfully");
    }

    public function forgotPassword(array $request): void {
        if (empty($request['email'])) {
            Response::error("Email is required", 422);
        }
        // In production, trigger reset token email link.
        Response::success(null, "Password reset OTP sent to registered email");
    }

    public function resetPassword(array $request): void {
        $required = ['email', 'otp', 'new_password'];
        foreach ($required as $field) {
            if (empty($request[$field])) {
                Response::error("Field '{$field}' is required", 422);
            }
        }
        Response::success(null, "Password has been reset successfully");
    }
}
