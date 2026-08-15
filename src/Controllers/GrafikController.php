<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Db\TursoConnection;

class GrafikController
{
    public function __construct(private TursoConnection $db) {}

    public static function create(): self { return new self(new TursoConnection()); }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $p          = $request->getQueryParams();
        $komoditiId = (int) ($p['komoditi_id'] ?? 0);
        $start      = $p['start'] ?? date('Y-m-d', strtotime('-30 days'));
        $end        = $p['end']   ?? date('Y-m-d');
        $mode       = in_array($p['mode'] ?? '', ['daily','weekly','monthly']) ? $p['mode'] : 'daily';
        $wilayahIds = isset($p['wilayah_id']) ? array_map('intval', (array) $p['wilayah_id']) : [];

        if (!$komoditiId) {
            return $this->json($response, ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'komoditi_id wajib diisi.']], 422);
        }

        $wilayahFilter = '';
        $args = [$komoditiId, $start, $end];

        if (!empty($wilayahIds)) {
            $placeholders = implode(',', array_fill(0, count($wilayahIds), '?'));
            $wilayahFilter = "AND ph.wilayah_id IN ($placeholders)";
            $args = array_merge($args, $wilayahIds);
        }

        $dateExpr = match($mode) {
            'weekly'  => "strftime('%Y-W%W', ph.tanggal)",
            'monthly' => "strftime('%Y-%m', ph.tanggal)",
            default   => 'ph.tanggal',
        };

        $rows = $this->db->query(
            "SELECT mw.id AS wilayah_id, mw.nama AS wilayah,
                    $dateExpr AS periode,
                    AVG(ph.harga) AS harga
             FROM price_history ph
             JOIN master_wilayah mw ON mw.id = ph.wilayah_id
             WHERE ph.komoditi_id = ?
               AND ph.tanggal BETWEEN ? AND ?
               $wilayahFilter
             GROUP BY mw.id, periode
             ORDER BY periode, mw.nama",
            $args
        );

        return $this->json($response, ['success' => true, 'data' => $rows]);
    }

    private function json(ResponseInterface $r, array $d, int $s = 200): ResponseInterface
    {
        $r->getBody()->write(json_encode($d));
        return $r->withStatus($s)->withHeader('Content-Type', 'application/json');
    }
}
