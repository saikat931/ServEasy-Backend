<?php

namespace App\Repositories;

use App\Helpers\DB;
use PDO;

class UserRepository {
    public function findByEmail(string $email): ?array {
        $stmt = DB::getConnection()->prepare("
            SELECT u.*, r.name as role 
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            WHERE u.email = :email
        ");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function findById(int $id): ?array {
        $stmt = DB::getConnection()->prepare("
            SELECT u.*, r.name as role 
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            WHERE u.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function findByPhone(string $phone): ?array {
        $stmt = DB::getConnection()->prepare("
            SELECT u.*, r.name as role 
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            WHERE u.phone = :phone
        ");
        $stmt->execute(['phone' => $phone]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function create(array $data): int {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            INSERT INTO users (first_name, last_name, email, phone, password_hash, status) 
            VALUES (:first_name, :last_name, :email, :phone, :password_hash, :status)
        ");
        $stmt->execute([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password_hash' => $data['password_hash'],
            'status' => $data['status'] ?? 'active'
        ]);
        return (int)$db->lastInsertId();
    }

    public function assignRole(int $userId, int $roleId): bool {
        $stmt = DB::getConnection()->prepare("
            INSERT INTO user_roles (user_id, role_id) 
            VALUES (:user_id, :role_id)
        ");
        return $stmt->execute([
            'user_id' => $userId,
            'role_id' => $roleId
        ]);
    }

    public function getRoleIdByName(string $name): ?int {
        $stmt = DB::getConnection()->prepare("SELECT id FROM roles WHERE name = :name");
        $stmt->execute(['name' => strtoupper($name)]);
        $res = $stmt->fetch();
        return $res ? (int)$res['id'] : null;
    }

    public function updateLocation(int $userId, float $lat, float $lng): bool {
        $stmt = DB::getConnection()->prepare("
            UPDATE users 
            SET location = ST_GeomFromText(:point) 
            WHERE id = :id
        ");
        return $stmt->execute([
            'id' => $userId,
            'point' => "POINT({$lat} {$lng})"
        ]);
    }

    public function updateProfile(int $userId, array $data): bool {
        $stmt = DB::getConnection()->prepare("
            UPDATE users 
            SET first_name = :first_name, last_name = :last_name, phone = :phone, avatar_url = :avatar_url
            WHERE id = :id
        ");
        return $stmt->execute([
            'id' => $userId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'],
            'avatar_url' => $data['avatar_url'] ?? null
        ]);
    }

    public function getAllUsers(int $page = 1, int $limit = 20): array {
        $offset = ($page - 1) * $limit;
        $db = DB::getConnection();
        
        $stmt = $db->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.status, u.created_at, r.name as role 
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            ORDER BY u.id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
