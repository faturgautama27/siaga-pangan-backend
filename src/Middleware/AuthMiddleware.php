<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Config\Settings;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorized('Token tidak ditemukan.');
        }

        $token = substr($authHeader, 7);

        try {
            $payload = JWT::decode($token, new Key(Settings::jwtSecret(), 'HS256'));
            // Inject user info ke request attributes
            $request = $request
                ->withAttribute('user_id', $payload->sub)
                ->withAttribute('user_role', $payload->role)
                ->withAttribute('user_name', $payload->name ?? '');
        } catch (\Exception $e) {
            return $this->unauthorized('Token tidak valid atau sudah kedaluwarsa.');
        }

        return $handler->handle($request);
    }

    private function unauthorized(string $message): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error'   => ['code' => 'UNAUTHORIZED', 'message' => $message],
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}
