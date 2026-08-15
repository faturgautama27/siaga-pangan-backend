<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Db\TursoConnection;

class PrognosaController
{
    public function __construct(private TursoConnection $db) {}

    public static function create(): self { return new self(new TursoConnection()); }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tanggal = $request->getQueryParams()['tanggal'] ?? date('Y-m-d');

        // Rata-rata provinsi per komoditi
        $avgRows = $this->db->query(
            'SELECT komoditi_id, AVG(harga) AS rata FROM price_history WHERE tanggal = ? GROUP BY komoditi_id',
            [$tanggal]
        );

        $result = [];
        foreach ($avgRows as $avg) {
            $kid  = (int) $avg['komoditi_id'];
            $rata = (float) $avg['rata'];

            // Top 5 tertinggi (potensi defisit)
            $defisit = $this->db->query(
                'SELECT ph.harga, mw.nama AS wilayah, mw.kode_kemendagri
                 FROM price_history ph JOIN master_wilayah mw ON mw.id = ph.wilayah_id
                 WHERE ph.komoditi_id = ? AND ph.tanggal = ?
                 ORDER BY ph.harga DESC LIMIT 5',
                [$kid, $tanggal]
            );

            // Top 5 terendah (potensi surplus)
            $surplus = $this->db->query(
                'SELECT ph.harga, mw.nama AS wilayah, mw.kode_kemendagri
                 FROM price_history ph JOIN master_wilayah mw ON mw.id = ph.wilayah_id
                 WHERE ph.komoditi_id = ? AND ph.tanggal = ?
                 ORDER BY ph.harga ASC LIMIT 5',
                [$kid, $tanggal]
            );

            $komoditi = $this->db->query(
                'SELECT nama FROM master_komoditi WHERE id = ? LIMIT 1',
                [$kid]
            );

            $result[] = [
                'komoditi_id' => $kid,
                'komoditi'    => $komoditi[0]['nama'] ?? '',
                'rata_provinsi' => round($rata, 2),
                'potensi_defisit' => $defisit,
                'potensi_surplus' => $surplus,
            ];
        }

        return $this->json($response, ['success' => true, 'data' => $result]);
    }

    private function json(ResponseInterface $r, array $d, int $s = 200): ResponseInterface
    {
        $r->getBody()->write(json_encode($d));
        return $r->withStatus($s)->withHeader('Content-Type', 'application/json');
    }
}
