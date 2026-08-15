<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Db\TursoConnection;
use App\Services\AuthService;

class AuthController
{
    public function __construct(
        private TursoConnection $db,
        private AuthService $authService
    ) {}

    public static function create(): self
    {
        $db = new TursoConnection();
        return new self($db, new AuthService($db));
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? json_decode((string) $request->getBody(), true) ?? []);
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        if (!$username || !$password) {
            return $this->json($response, [
                'success' => false,
                'error'   => ['code' => 'VALIDATION_ERROR', 'message' => 'Username dan password wajib diisi.'],
            ], 422);
        }

        $result = $this->authService->login($username, $password);

        if (!$result) {
            return $this->json($response, [
                'success' => false,
                'error'   => ['code' => 'INVALID_CREDENTIALS', 'message' => 'Username atau password salah.'],
            ], 401);
        }

        return $this->json($response, ['success' => true, 'data' => $result]);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
