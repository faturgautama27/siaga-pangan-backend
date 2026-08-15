<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Db\TursoConnection;

class LaporanKoordinasiController
{
    public function __construct(private TursoConnection $db) {}

    public static function create(): self { return new self(new TursoConnection()); }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? json_decode((string) $request->getBody(), true) ?? []);

        $wilayahId   = (int) ($body['wilayah_id'] ?? 0);
        $komoditiId  = (int) ($body['komoditi_id'] ?? 0);
        $tanggal     = trim($body['tanggal'] ?? '');
        $isiLaporan  = trim($body['isi_laporan'] ?? '');
        $picId       = (int) ($body['pic_id'] ?? 0);
        $koordinatorId = (int) ($body['koordinator_id'] ?? 0);
        $status      = in_array($body['status'] ?? '', ['Koordinasi', 'Aman']) ? $body['status'] : 'Koordinasi';

        if (!$wilayahId || !$komoditiId || !$tanggal || !$isiLaporan || !$picId || !$koordinatorId) {
            return $this->json($response, ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Semua field wajib diisi.']], 422);
        }

        $result = $this->db->execute(
            'INSERT INTO laporan_koordinasi (wilayah_id, komoditi_id, tanggal, isi_laporan, pic_id, koordinator_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$wilayahId, $komoditiId, $tanggal, $isiLaporan, $picId, $koordinatorId, $status]
        );

        return $this->json($response, ['success' => true, 'data' => ['id' => $result['last_insert_id']]], 201);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $p          = $request->getQueryParams();
        $where      = ['1=1'];
        $args       = [];

        if (!empty($p['komoditi_id'])) { $where[] = 'lk.komoditi_id = ?'; $args[] = (int) $p['komoditi_id']; }
        if (!empty($p['wilayah_id']))  { $where[] = 'lk.wilayah_id = ?';  $args[] = (int) $p['wilayah_id']; }
        if (!empty($p['start']))       { $where[] = 'lk.tanggal >= ?';    $args[] = $p['start']; }
        if (!empty($p['end']))         { $where[] = 'lk.tanggal <= ?';    $args[] = $p['end']; }

        $whereStr = implode(' AND ', $where);
        $page   = max(1, (int) ($p['page'] ?? 1));
        $limit  = min(100, (int) ($p['limit'] ?? 50));
        $offset = ($page - 1) * $limit;

        $rows = $this->db->query(
            "SELECT lk.*, mw.nama AS wilayah, mk.nama AS komoditi,
                    pk1.nama AS pic, pk2.nama AS koordinator
             FROM laporan_koordinasi lk
             JOIN master_wilayah mw ON mw.id = lk.wilayah_id
             JOIN master_komoditi mk ON mk.id = lk.komoditi_id
             LEFT JOIN pic_koordinator pk1 ON pk1.id = lk.pic_id
             LEFT JOIN pic_koordinator pk2 ON pk2.id = lk.koordinator_id
             WHERE $whereStr ORDER BY lk.created_at DESC LIMIT ? OFFSET ?",
            [...$args, $limit, $offset]
        );

        return $this->json($response, ['success' => true, 'data' => $rows]);
    }

    private function json(ResponseInterface $r, array $d, int $s = 200): ResponseInterface
    {
        $r->getBody()->write(json_encode($d));
        return $r->withStatus($s)->withHeader('Content-Type', 'application/json');
    }
}
