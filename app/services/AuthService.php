<?php

namespace App\Services;

use App\Repositories\UserRepository;
use App\Repositories\WalletRepository;
use App\Helpers\JWT;
use Exception;

class AuthService {
    private UserRepository $userRepo;
    private WalletRepository $walletRepo;
    private array $config;

    public function __construct() {
        $this->userRepo = new UserRepository();
        $this->walletRepo = new WalletRepository();
        $this->config = require dirname(__DIR__, 2) . '/config/app.php';
    }

    public function register(array $data): array {
        // Validation check
        if ($this->userRepo->findByEmail($data['email'])) {
            throw new Exception("Email already registered");
        }
        if ($this->userRepo->findByPhone($data['phone'])) {
            throw new Exception("Phone number already registered");
        }

        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
        
        $userId = $this->userRepo->create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password_hash' => $passwordHash,
            'status' => 'active'
        ]);

        // Assign Role
        $roleName = $data['role'] ?? 'CUSTOMER';
        $roleId = $this->userRepo->getRoleIdByName($roleName);
        if (!$roleId) {
            throw new Exception("Invalid role specified");
        }
        $this->userRepo->assignRole($userId, $roleId);

        // Initialize Wallet for new user
        $this->walletRepo->createWallet($userId);

        return $this->login($data['email'], $data['password']);
    }

    public function login(string $email, string $password): array {
        $user = $this->userRepo->findByEmail($email);
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new Exception("Invalid email or password");
        }

        if ($user['status'] !== 'active') {
            throw new Exception("Account is inactive or suspended");
        }

        return $this->generateTokens($user);
    }

    public function generateTokens(array $user): array {
        $payload = [
            'user_id' => (int)($user['id'] ?? 0),
            'email' => $user['email'] ?? '',
            'role' => $user['role'] ?? 'USER',
            'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))
        ];

        $accessToken = JWT::encode($payload, $this->config['jwt']['secret'], $this->config['jwt']['access_ttl']);
        $refreshToken = JWT::encode(['user_id' => $user['id'] ?? 0], $this->config['jwt']['refresh_secret'], $this->config['jwt']['refresh_ttl']);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'user' => [
                'id' => $user['id'] ?? null,
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
                'email' => $user['email'] ?? '',
                'phone' => $user['phone'] ?? '',
                'role' => $user['role'] ?? 'USER',
                'avatar_url' => $user['avatar_url'] ?? null
            ]
        ];
    }

    public function refreshToken(string $refreshToken): array {
        try {
            $payload = JWT::decode($refreshToken, $this->config['jwt']['refresh_secret']);
            $userId = (int)$payload['user_id'];
            
            $user = $this->userRepo->findById($userId);
            if (!$user || $user['status'] !== 'active') {
                throw new Exception("User account is disabled");
            }

            return $this->generateTokens($user);
        } catch (Exception $e) {
            throw new Exception("Invalid refresh token: " . $e->getMessage());
        }
    }
}
