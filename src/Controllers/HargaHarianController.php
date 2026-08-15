<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Db\TursoConnection;

class HargaHarianController
{
    public function __construct(private TursoConnection $db) {}

    public static function create(): self
    {
        return new self(new TursoConnection());
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $p         = $request->getQueryParams();
        $start     = $p['start'] ?? null;
        $end       = $p['end'] ?? null;
        $wilayahId = isset($p['wilayah_id']) ? (int) $p['wilayah_id'] : null;
        $komoditiId = isset($p['komoditi_id']) ? (int) $p['komoditi_id'] : null;
        $page      = max(1, (int) ($p['page'] ?? 1));
        $limit     = min(100, max(1, (int) ($p['limit'] ?? 50)));
        $offset    = ($page - 1) * $limit;

        $where  = ['1=1'];
        $args   = [];

        if ($start) { $where[] = 'ph.tanggal >= ?'; $args[] = $start; }
        if ($end)   { $where[] = 'ph.tanggal <= ?'; $args[] = $end; }
        if ($wilayahId)  { $where[] = 'ph.wilayah_id = ?';  $args[] = $wilayahId; }
        if ($komoditiId) { $where[] = 'ph.komoditi_id = ?'; $args[] = $komoditiId; }

        $whereStr = implode(' AND ', $where);

        $rows = $this->db->query(
            "SELECT ph.id, ph.tanggal, ph.harga,
                    mw.nama AS wilayah, mw.kode_kemendagri,
                    mk.nama AS komoditi, mk.satuan
             FROM price_history ph
             JOIN master_wilayah mw ON mw.id = ph.wilayah_id
             JOIN master_komoditi mk ON mk.id = ph.komoditi_id
             WHERE $whereStr
             ORDER BY ph.tanggal DESC, mw.nama, mk.nama
             LIMIT ? OFFSET ?",
            [...$args, $limit, $offset]
        );

        $total = $this->db->query(
            "SELECT COUNT(*) AS total FROM price_history ph WHERE $whereStr",
            $args
        )[0]['total'] ?? 0;

        return $this->json($response, [
            'success' => true,
            'data'    => $rows,
            'meta'    => ['page' => $page, 'limit' => $limit, 'total' => (int) $total],
        ]);
    }

    public function wilayah(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows = $this->db->query('SELECT id, kode_kemendagri, nama, is_ihk FROM master_wilayah ORDER BY nama');
        return $this->json($response, ['success' => true, 'data' => $rows]);
    }

    public function komoditi(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows = $this->db->query('SELECT id, nama, kategori, satuan FROM master_komoditi ORDER BY nama');
        return $this->json($response, ['success' => true, 'data' => $rows]);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
