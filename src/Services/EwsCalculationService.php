<?php

declare(strict_types=1);

namespace App\Services;

use App\Db\TursoConnection;

class EwsCalculationService
{
    public function __construct(private TursoConnection $db) {}

    /**
     * Hitung deviasi harga per wilayah + komoditi terhadap rata-rata provinsi.
     * Kembalikan hanya yang melebihi threshold.
     */
    public function hitungDeviasi(string $tanggal): array
    {
        // Ambil threshold dari konfigurasi
        $cfg = $this->db->query(
            "SELECT nilai FROM konfigurasi_sistem WHERE kunci = 'ews_threshold_persen' LIMIT 1"
        );
        $threshold = (float) ($cfg[0]['nilai'] ?? 5);

        // Rata-rata provinsi per komoditi
        $avgRows = $this->db->query(
            'SELECT komoditi_id, AVG(harga) AS rata FROM price_history WHERE tanggal = ? GROUP BY komoditi_id',
            [$tanggal]
        );
        $rataMap = array_column($avgRows, 'rata', 'komoditi_id');

        // Semua harga pada tanggal tersebut
        $prices = $this->db->query(
            'SELECT ph.wilayah_id, ph.komoditi_id, ph.harga,
                    mw.nama AS wilayah, mk.nama AS komoditi
             FROM price_history ph
             JOIN master_wilayah mw ON mw.id = ph.wilayah_id
             JOIN master_komoditi mk ON mk.id = ph.komoditi_id
             WHERE ph.tanggal = ?',
            [$tanggal]
        );

        $alerts = [];
        foreach ($prices as $row) {
            $rata = (float) ($rataMap[$row['komoditi_id']] ?? 0);
            if ($rata == 0) continue;

            $harga   = (float) $row['harga'];
            $deviasi = (($harga - $rata) / $rata) * 100;

            if (abs($deviasi) >= $threshold) {
                $alerts[] = [
                    'wilayah_id'  => (int) $row['wilayah_id'],
                    'wilayah'     => $row['wilayah'],
                    'komoditi_id' => (int) $row['komoditi_id'],
                    'komoditi'    => $row['komoditi'],
                    'harga'       => $harga,
                    'rata_provinsi' => round($rata, 2),
                    'deviasi_persen' => round($deviasi, 2),
                    'threshold_persen' => $threshold,
                ];
            }
        }

        return $alerts;
    }
}
