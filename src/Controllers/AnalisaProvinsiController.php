<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Db\TursoConnection;
use App\Services\PriceCalculationService;

class AnalisaProvinsiController
{
    public function __construct(private TursoConnection $db, private PriceCalculationService $calc) {}

    public static function create(): self
    {
        $db = new TursoConnection();
        return new self($db, new PriceCalculationService($db));
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $p          = $request->getQueryParams();
        $komoditiId = (int) ($p['komoditi_id'] ?? 0);
        $tanggal    = $p['tanggal'] ?? date('Y-m-d');

        if (!$komoditiId) {
            return $this->json($response, ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'komoditi_id wajib diisi.']], 422);
        }

        $rows = $this->calc->analisaProvinsi($komoditiId, $tanggal);
        return $this->json($response, ['success' => true, 'data' => $rows]);
    }

    private function json(ResponseInterface $r, array $d, int $s = 200): ResponseInterface
    {
        $r->getBody()->write(json_encode($d));
        return $r->withStatus($s)->withHeader('Content-Type', 'application/json');
    }
}
