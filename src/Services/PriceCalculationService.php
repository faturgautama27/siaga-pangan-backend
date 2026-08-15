<?php

declare(strict_types=1);

namespace App\Services;

use App\Db\TursoConnection;

/**
 * Kalkulasi status harga: naik/turun/tetap, HET/HAP, GAP, rata-rata provinsi.
 */
class PriceCalculationService
{
    public function __construct(private TursoConnection $db) {}

    /**
     * Status perubahan harga antara dua tanggal.
     */
    public function statusPerubahan(float $hargaBaru, float $hargaLama): array
    {
        $selisih = $hargaBaru - $hargaLama;
        $status  = match(true) {
            $selisih > 0  => 'Naik',
            $selisih < 0  => 'Turun',
            default       => 'Tetap',
        };
        return ['status' => $status, 'selisih' => $selisih];
    }

    /**
     * Status harga terhadap HET/HAP.
     */
    public function statusHetHap(float $harga, ?float $het, ?float $hap, ?float $hapMin, ?float $hapMaks): string
    {
        if ($het === null && $hap === null && $hapMin === null && $hapMaks === null) {
            return 'Tidak Ada HET/HAP';
        }
        if ($het !== null && $harga == $het) return 'Sama dengan HET';
        if ($het !== null && $harga > $het)  return 'Di Atas HET';
        if ($hap !== null && $harga == $hap) return 'Sama dengan HAP';

        if ($hapMin !== null && $hapMaks !== null) {
            if ($harga < $hapMin)  return 'Di Bawah HAP Min';
            if ($harga > $hapMaks) return 'Di Atas HAP Maks';
            return 'Di Antara HAP';
        }

        if ($hap !== null) {
            return $harga > $hap ? 'Di Atas HAP' : 'Di Bawah HAP';
        }

        if ($het !== null) {
            return $harga < $het ? 'Di Bawah HET' : 'Normal';
        }

        return 'Normal';
    }

    /**
     * Hitung GAP harga terhadap HET/HAP dan rata-rata provinsi.
     */
    public function hitungGap(float $harga, ?float $het, ?float $hap, ?float $rataProvinsi): array
    {
        return [
            'gap_het'          => $het !== null ? round($harga - $het, 2) : null,
            'gap_hap'          => $hap !== null ? round($harga - $hap, 2) : null,
            'gap_rata_provinsi' => $rataProvinsi !== null ? round($harga - $rataProvinsi, 2) : null,
        ];
    }

    /**
     * Data analisa provinsi: semua wilayah untuk satu komoditi + tanggal.
     */
    public function analisaProvinsi(int $komoditiId, string $tanggal): array
    {
        // Ambil semua harga wilayah
        $prices = $this->db->query(
            'SELECT ph.harga, mw.id AS wilayah_id, mw.nama AS wilayah, mw.kode_kemendagri
             FROM price_history ph
             JOIN master_wilayah mw ON mw.id = ph.wilayah_id
             WHERE ph.komoditi_id = ? AND ph.tanggal = ?
             ORDER BY ph.harga DESC',
            [$komoditiId, $tanggal]
        );

        if (empty($prices)) return [];

        // Rata-rata provinsi
        $rataProvinsi = array_sum(array_column($prices, 'harga')) / count($prices);

        // Ambil referensi HET/HAP
        $ref = $this->db->query(
            'SELECT het, hap, hap_minimal, hap_maksimal FROM referensi_het_hap
             WHERE komoditi_id = ? AND berlaku_mulai <= ? ORDER BY berlaku_mulai DESC LIMIT 1',
            [$komoditiId, $tanggal]
        )[0] ?? null;

        $het     = $ref ? (float) $ref['het']          : null;
        $hap     = $ref ? (float) $ref['hap']          : null;
        $hapMin  = $ref ? (float) $ref['hap_minimal']  : null;
        $hapMaks = $ref ? (float) $ref['hap_maksimal'] : null;

        $result = [];
        foreach ($prices as $row) {
            $harga = (float) $row['harga'];
            $result[] = [
                'wilayah_id'     => (int) $row['wilayah_id'],
                'wilayah'        => $row['wilayah'],
                'kode_kemendagri' => $row['kode_kemendagri'],
                'harga'          => $harga,
                'rata_provinsi'  => round($rataProvinsi, 2),
                'status_het_hap' => $this->statusHetHap($harga, $het, $hap, $hapMin, $hapMaks),
                'status_provinsi' => $harga > $rataProvinsi ? 'Di Atas Prov' : ($harga < $rataProvinsi ? 'Di Bawah Prov' : 'Sama Prov'),
                ...$this->hitungGap($harga, $het, $hap, $rataProvinsi),
            ];
        }

        return $result;
    }

    /**
     * Data IHK: perbandingan harga dua tanggal untuk satu wilayah.
     */
    public function compareIhk(int $wilayahId, string $tanggal1, string $tanggal2): array
    {
        $prices1 = $this->db->query(
            'SELECT ph.harga, mk.id AS komoditi_id, mk.nama AS komoditi, mk.satuan
             FROM price_history ph JOIN master_komoditi mk ON mk.id = ph.komoditi_id
             WHERE ph.wilayah_id = ? AND ph.tanggal = ?',
            [$wilayahId, $tanggal1]
        );
        $prices2 = $this->db->query(
            'SELECT ph.harga, mk.id AS komoditi_id
             FROM price_history ph JOIN master_komoditi mk ON mk.id = ph.komoditi_id
             WHERE ph.wilayah_id = ? AND ph.tanggal = ?',
            [$wilayahId, $tanggal2]
        );

        $map2 = array_column($prices2, 'harga', 'komoditi_id');

        $ref = $this->db->query(
            'SELECT komoditi_id, het, hap, hap_minimal, hap_maksimal FROM referensi_het_hap
             WHERE berlaku_mulai <= ? ORDER BY berlaku_mulai DESC',
            [$tanggal2]
        );
        $refMap = [];
        foreach ($ref as $r) {
            $refMap[$r['komoditi_id']] = $r;
        }

        $result = [];
        foreach ($prices1 as $row) {
            $kid   = $row['komoditi_id'];
            $h1    = (float) $row['harga'];
            $h2    = isset($map2[$kid]) ? (float) $map2[$kid] : null;
            $r     = $refMap[$kid] ?? null;

            $statusPerubahan = $h2 !== null ? $this->statusPerubahan($h2, $h1) : null;
            $statusHetHap    = $h2 !== null ? $this->statusHetHap(
                $h2,
                $r ? (float) $r['het'] : null,
                $r ? (float) $r['hap'] : null,
                $r ? (float) $r['hap_minimal'] : null,
                $r ? (float) $r['hap_maksimal'] : null,
            ) : null;

            $result[] = [
                'komoditi_id'     => (int) $kid,
                'komoditi'        => $row['komoditi'],
                'satuan'          => $row['satuan'],
                'harga_tanggal1'  => $h1,
                'harga_tanggal2'  => $h2,
                'status_perubahan' => $statusPerubahan,
                'status_het_hap'  => $statusHetHap,
            ];
        }

        return $result;
    }
}
