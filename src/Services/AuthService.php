<?php

declare(strict_types=1);

namespace App\Services;

use App\Db\TursoConnection;
use App\Config\Settings;
use Firebase\JWT\JWT;

class AuthService
{
    public function __construct(private TursoConnection $db) {}

    /**
     * Login user — verifikasi password, kembalikan token + user info.
     * @return array|null null bila credentials salah
     */
    public function login(string $username, string $password): ?array
    {
        $rows = $this->db->query(
            'SELECT id, username, password_hash, nama, role FROM users WHERE username = ? LIMIT 1',
            [$username]
        );

        if (empty($rows)) {
            return null;
        }

        $user = $rows[0];

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        $now     = time();
        $payload = [
            'iss'  => 'siaga-pangan',
            'iat'  => $now,
            'exp'  => $now + Settings::jwtTtl(),
            'sub'  => (int) $user['id'],
            'role' => $user['role'],
            'name' => $user['nama'],
        ];

        $token = JWT::encode($payload, Settings::jwtSecret(), 'HS256');

        return [
            'token'      => $token,
            'expires_in' => Settings::jwtTtl(),
            'user'       => [
                'id'   => (int) $user['id'],
                'nama' => $user['nama'],
                'role' => $user['role'],
            ],
        ];
    }

    /**
     * Hash password untuk seed / ganti password.
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}
