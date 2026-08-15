<?php

declare(strict_types=1);

namespace App\Config;

class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    public static function tursoUrl(): string
    {
        $url = self::get('TURSO_DATABASE_URL');
        if (!$url) {
            throw new \RuntimeException('TURSO_DATABASE_URL environment variable is not set.');
        }
        return rtrim($url, '/');
    }

    public static function tursoToken(): string
    {
        $token = self::get('TURSO_AUTH_TOKEN');
        if (!$token) {
            throw new \RuntimeException('TURSO_AUTH_TOKEN environment variable is not set.');
        }
        return $token;
    }

    public static function jwtSecret(): string
    {
        return self::get('JWT_SECRET', 'changeme-set-in-env');
    }

    public static function jwtTtl(): int
    {
        return (int) self::get('JWT_TTL_SECONDS', 28800); // 8 jam default
    }

    public static function allowedOrigins(): array
    {
        $origins = self::get('CORS_ALLOWED_ORIGINS', 'http://localhost:4200');
        return array_map('trim', explode(',', $origins));
    }

    public static function maxUploadBytes(): int
    {
        return (int) self::get('MAX_UPLOAD_BYTES', 10485760); // 10 MB default
    }
}
