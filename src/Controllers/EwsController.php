<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Db\TursoConnection;
use App\Services\EwsCalculationService;

class EwsController
{
    public function __construct(private TursoConnection $db, private EwsCalculationService $ews) {}

    public static function create(): self
    {
        $db = new TursoConnection();
        return new self($db, new EwsCalculationService($db));
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tanggal = $request->getQueryParams()['tanggal'] ?? date('Y-m-d');
        $alerts  = $this->ews->hitungDeviasi($tanggal);

        // Enrich dengan mapping PIC/Koordinator
        foreach ($alerts as &$alert) {
            $pic = $this->db->query(
                'SELECT pk.nama AS pic_nama, pk2.nama AS koordinator_nama
                 FROM penanggung_jawab pj
                 JOIN pic_koordinator pk  ON pk.id  = pj.pic_id
                 JOIN pic_koordinator pk2 ON pk2.id = pj.koordinator_id
                 WHERE pj.wilayah_id = ?
                   AND (pj.komoditi_id = ? OR pj.komoditi_id IS NULL)
                 ORDER BY pj.komoditi_id DESC LIMIT 1',
                [$alert['wilayah_id'], $alert['komoditi_id']]
            );
            $alert['pic']         = $pic[0]['pic_nama'] ?? null;
            $alert['koordinator'] = $pic[0]['koordinator_nama'] ?? null;

            // Status laporan terakhir
            $laporan = $this->db->query(
                'SELECT status FROM laporan_koordinasi
                 WHERE wilayah_id = ? AND komoditi_id = ? AND tanggal = ?
                 ORDER BY created_at DESC LIMIT 1',
                [$alert['wilayah_id'], $alert['komoditi_id'], $tanggal]
            );
            $alert['status_laporan'] = $laporan[0]['status'] ?? 'Belum Lapor';
        }

        return $this->json($response, ['success' => true, 'data' => $alerts]);
    }

    private function json(ResponseInterface $r, array $d, int $s = 200): ResponseInterface
    {
        $r->getBody()->write(json_encode($d));
        return $r->withStatus($s)->withHeader('Content-Type', 'application/json');
    }
}
