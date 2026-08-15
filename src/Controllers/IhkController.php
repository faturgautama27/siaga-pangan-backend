<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Db\TursoConnection;
use App\Services\PriceCalculationService;

class IhkController
{
    public function __construct(private TursoConnection $db, private PriceCalculationService $calc) {}

    public static function create(): self
    {
        $db = new TursoConnection();
        return new self($db, new PriceCalculationService($db));
    }

    public function compare(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $p         = $request->getQueryParams();
        $wilayahId = (int) ($p['wilayah_id'] ?? 0);
        $tanggal1  = $p['tanggal1'] ?? null;
        $tanggal2  = $p['tanggal2'] ?? null;

        if (!$wilayahId || !$tanggal1 || !$tanggal2) {
            return $this->json($response, ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'wilayah_id, tanggal1, tanggal2 wajib diisi.']], 422);
        }

        $rows = $this->calc->compareIhk($wilayahId, $tanggal1, $tanggal2);
        return $this->json($response, ['success' => true, 'data' => $rows]);
    }

    private function json(ResponseInterface $r, array $d, int $s = 200): ResponseInterface
    {
        $r->getBody()->write(json_encode($d));
        return $r->withStatus($s)->withHeader('Content-Type', 'application/json');
    }
}
