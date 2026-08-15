<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Db\TursoConnection;
use App\Services\EwsCalculationService;

class ExecutiveSummaryController
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
        $weekAgo = date('Y-m-d', strtotime('-7 days', strtotime($tanggal)));

        // KPI 1: jumlah komoditi di atas HET/HAP
        $diAtasHet = $this->db->query(
            'SELECT COUNT(DISTINCT ph.komoditi_id) AS total
             FROM price_history ph
             JOIN referensi_het_hap r ON r.komoditi_id = ph.komoditi_id
             WHERE ph.tanggal = ? AND ph.harga > COALESCE(r.het, r.hap, 0)
               AND r.berlaku_mulai = (SELECT MAX(berlaku_mulai) FROM referensi_het_hap r2 WHERE r2.komoditi_id = r.komoditi_id AND r2.berlaku_mulai <= ?)',
            [$tanggal, $tanggal]
        )[0]['total'] ?? 0;

        // KPI 2: jumlah alert EWS aktif
        $alerts = $this->ews->hitungDeviasi($tanggal);

        // KPI 3: laporan masuk vs wajib lapor
        $laporanMasuk = $this->db->query(
            'SELECT COUNT(*) AS total FROM laporan_koordinasi WHERE tanggal = ?',
            [$tanggal]
        )[0]['total'] ?? 0;

        // KPI 4: status per wilayah (aman/waspada/koordinasi)
        $statusWilayah = $this->hitungStatusWilayah($alerts);

        // Tren 5 komoditi volatil (range harga seminggu)
        $volatil = $this->db->query(
            'SELECT mk.id, mk.nama,
                    MAX(ph.harga) - MIN(ph.harga) AS range_harga,
                    AVG(ph.harga) AS rata
             FROM price_history ph
             JOIN master_komoditi mk ON mk.id = ph.komoditi_id
             WHERE ph.tanggal BETWEEN ? AND ?
             GROUP BY mk.id ORDER BY range_harga DESC LIMIT 5',
            [$weekAgo, $tanggal]
        );

        // Laporan belum aman
        $belumAman = $this->db->query(
            'SELECT lk.*, mw.nama AS wilayah, mk.nama AS komoditi
             FROM laporan_koordinasi lk
             JOIN master_wilayah mw ON mw.id = lk.wilayah_id
             JOIN master_komoditi mk ON mk.id = lk.komoditi_id
             WHERE lk.tanggal = ? AND lk.status != \'Aman\'
             ORDER BY lk.created_at DESC LIMIT 10',
            [$tanggal]
        );

        return $this->json($response, [
            'success' => true,
            'data'    => [
                'tanggal'         => $tanggal,
                'kpi'             => [
                    'komoditi_di_atas_het_hap' => (int) $diAtasHet,
                    'alert_ews_aktif'          => count($alerts),
                    'laporan_masuk'            => (int) $laporanMasuk,
                ],
                'status_wilayah'  => $statusWilayah,
                'komoditi_volatil' => $volatil,
                'tindak_lanjut'   => $belumAman,
            ],
        ]);
    }

    private function hitungStatusWilayah(array $alerts): array
    {
        $wilayahAlert = [];
        foreach ($alerts as $a) {
            $wilayahAlert[$a['wilayah_id']] = ($wilayahAlert[$a['wilayah_id']] ?? 0) + 1;
        }

        $wilayah = $this->db->query('SELECT id, nama, kode_kemendagri FROM master_wilayah ORDER BY nama');
        $result  = [];
        foreach ($wilayah as $w) {
            $count    = $wilayahAlert[(int) $w['id']] ?? 0;
            $status   = match(true) {
                $count === 0 => 'Aman',
                $count <= 2  => 'Waspada',
                default      => 'Koordinasi',
            };
            $result[] = [
                'wilayah_id'      => (int) $w['id'],
                'wilayah'         => $w['nama'],
                'kode_kemendagri' => $w['kode_kemendagri'],
                'jumlah_alert'    => $count,
                'status'          => $status,
            ];
        }
        return $result;
    }

    private function json(ResponseInterface $r, array $d, int $s = 200): ResponseInterface
    {
        $r->getBody()->write(json_encode($d));
        return $r->withStatus($s)->withHeader('Content-Type', 'application/json');
    }
}
