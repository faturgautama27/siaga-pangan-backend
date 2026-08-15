<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Db\TursoConnection;

class PasarController
{
    public function __construct(private TursoConnection $db) {}

    public static function create(): self { return new self(new TursoConnection()); }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $p         = $request->getQueryParams();
        $wilayahId = isset($p['wilayah_id']) ? (int) $p['wilayah_id'] : null;

        $where = ['1=1'];
        $args  = [];
        if ($wilayahId) { $where[] = 'pp.wilayah_id = ?'; $args[] = $wilayahId; }

        $rows = $this->db->query(
            'SELECT pp.id, pp.nama_pasar, pp.alamat, pp.latitude, pp.longitude,
                    mw.nama AS wilayah, mw.kode_kemendagri
             FROM pasar_pantauan pp
             JOIN master_wilayah mw ON mw.id = pp.wilayah_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY mw.nama, pp.nama_pasar',
            $args
        );

        // Tambah URL Google Maps
        foreach ($rows as &$row) {
            if ($row['latitude'] && $row['longitude']) {
                $row['maps_url'] = "https://maps.google.com/?q={$row['latitude']},{$row['longitude']}";
            } else {
                $row['maps_url'] = null;
            }
        }

        return $this->json($response, ['success' => true, 'data' => $rows]);
    }

    private function json(ResponseInterface $r, array $d, int $s = 200): ResponseInterface
    {
        $r->getBody()->write(json_encode($d));
        return $r->withStatus($s)->withHeader('Content-Type', 'application/json');
    }
}
