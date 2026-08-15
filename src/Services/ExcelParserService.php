<?php

declare(strict_types=1);

namespace App\Services;

use App\Db\TursoConnection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Parse file Excel tabulasi harga harian.
 *
 * Format kolom:
 *   A = Kode Kemendagri, B = Kabupaten/Kota (merged cell),
 *   C = Nama Variant (komoditi), D+ = harga per tanggal (header DD/MM/YY)
 */
class ExcelParserService
{
    public function __construct(private TursoConnection $db) {}

    /**
     * Parse raw bytes Excel, upsert ke price_history, catat upload_log.
     *
     * @param  string $content  raw file bytes
     * @param  string $filename nama file asli
     * @param  int    $userId   ID user yang upload
     * @return array  ringkasan hasil
     */
    public function parse(string $content, string $filename, int $userId): array
    {
        // Load dari string langsung — tidak tulis ke filesystem
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        file_put_contents($tmpFile, $content);

        try {
            $spreadsheet = IOFactory::load($tmpFile);
        } finally {
            @unlink($tmpFile);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            throw new \RuntimeException('File Excel kosong atau tidak dapat dibaca.');
        }

        // Baris 0 = header
        $header    = $rows[0];
        $dateMap   = $this->parseDateColumns($header); // [colIndex => 'YYYY-MM-DD']

        if (empty($dateMap)) {
            throw new \RuntimeException('Tidak ditemukan kolom tanggal (format DD/MM/YY) di baris header.');
        }

        $inserted   = 0;
        $updated    = 0;
        $skipped    = 0;
        $skipDetail = [];

        $lastKode  = null;
        $lastNama  = null;

        foreach (array_slice($rows, 1) as $rowIdx => $row) {
            $lineNum = $rowIdx + 2; // baris Excel (1-indexed, +1 untuk header)

            // Forward-fill kolom A dan B
            $kodeKemendagri = trim((string) ($row[0] ?? ''));
            $namaWilayah    = trim((string) ($row[1] ?? ''));

            if ($kodeKemendagri !== '') {
                $lastKode = $kodeKemendagri;
                $lastNama = $namaWilayah;
            } else {
                $kodeKemendagri = $lastKode ?? '';
                $namaWilayah    = $lastNama ?? '';
            }

            $namaVariant = trim((string) ($row[2] ?? ''));

            if (!$kodeKemendagri || !$namaVariant) {
                continue; // skip baris kosong total
            }

            // Resolve wilayah_id
            $wilayahId = $this->resolveWilayahId($kodeKemendagri);
            if (!$wilayahId) {
                $skipDetail[] = ['row' => $lineNum, 'reason' => "Kode Kemendagri '$kodeKemendagri' tidak ditemukan di master_wilayah."];
                $skipped++;
                continue;
            }

            // Resolve komoditi_id (auto-insert bila belum ada)
            $komoditiId = $this->resolveKomoditiId($namaVariant);

            // Upsert per kolom tanggal
            foreach ($dateMap as $colIdx => $tanggal) {
                $rawHarga = $row[$colIdx] ?? null;

                if ($rawHarga === null || $rawHarga === '') {
                    $skipped++;
                    $skipDetail[] = ['row' => $lineNum, 'reason' => "Harga kosong untuk tanggal $tanggal, komoditi '$namaVariant'."];
                    continue;
                }

                $harga = $this->parseHarga($rawHarga);

                if ($harga === null) {
                    $skipped++;
                    $skipDetail[] = ['row' => $lineNum, 'reason' => "Nilai harga '$rawHarga' tidak valid untuk tanggal $tanggal, komoditi '$namaVariant'."];
                    continue;
                }

                $result = $this->upsertPrice($wilayahId, $komoditiId, $tanggal, $harga);
                if ($result === 'inserted') $inserted++;
                else $updated++;
            }
        }

        // Catat ke upload_log
        $this->db->execute(
            'INSERT INTO upload_log (nama_file, uploaded_by, jumlah_insert, jumlah_update, jumlah_skip, detail_skip)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$filename, $userId, $inserted, $updated, $skipped, json_encode($skipDetail)]
        );

        return [
            'inserted'    => $inserted,
            'updated'     => $updated,
            'skipped'     => $skipped,
            'skip_detail' => $skipDetail,
        ];
    }

    /**
     * Parse kolom tanggal dari baris header.
     * Format yang diakui: DD/MM/YY atau DD/MM/YYYY
     * @return array [colIndex => 'YYYY-MM-DD']
     */
    private function parseDateColumns(array $header): array
    {
        $map = [];
        foreach ($header as $i => $cell) {
            if ($i < 3) continue; // kolom A, B, C bukan tanggal

            $cell = trim((string) ($cell ?? ''));
            if (!$cell) continue;

            // Coba format DD/MM/YY atau DD/MM/YYYY
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $cell, $m)) {
                $day   = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
                $year  = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
                $map[$i] = "$year-$month-$day";
            }
        }
        return $map;
    }

    /**
     * Parse nilai harga: strip koma ribuan, konversi ke float.
     */
    private function parseHarga(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        // Jika sudah integer, langsung konversi
        if (is_int($raw)) {
            return (float) $raw;
        }

        // Normalisasi string: strip koma dan titik sebagai pemisah ribuan
        // Format Indonesia: 31,333 atau 31.333 = tiga puluh satu ribu tiga ratus tiga puluh tiga
        $cleaned = str_replace([',', '.'], '', (string) $raw);

        if (!is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    /**
     * Resolve wilayah_id dari kode_kemendagri.
     */
    private function resolveWilayahId(string $kode): ?int
    {
        $rows = $this->db->query(
            'SELECT id FROM master_wilayah WHERE kode_kemendagri = ? LIMIT 1',
            [$kode]
        );
        return !empty($rows) ? (int) $rows[0]['id'] : null;
    }

    /**
     * Resolve komoditi_id dari nama variant (case-insensitive).
     * Auto-insert bila belum ada.
     */
    private function resolveKomoditiId(string $nama): int
    {
        $rows = $this->db->query(
            'SELECT id FROM master_komoditi WHERE LOWER(nama) = LOWER(?) LIMIT 1',
            [$nama]
        );

        if (!empty($rows)) {
            return (int) $rows[0]['id'];
        }

        // Auto-insert
        $result = $this->db->execute(
            'INSERT INTO master_komoditi (nama) VALUES (?)',
            [$nama]
        );

        return (int) $result['last_insert_id'];
    }

    /**
     * Upsert satu baris ke price_history.
     * @return string 'inserted' | 'updated'
     */
    private function upsertPrice(int $wilayahId, int $komoditiId, string $tanggal, float $harga): string
    {
        // Cek apakah sudah ada
        $existing = $this->db->query(
            'SELECT id FROM price_history WHERE wilayah_id = ? AND komoditi_id = ? AND tanggal = ? LIMIT 1',
            [$wilayahId, $komoditiId, $tanggal]
        );

        if (!empty($existing)) {
            $this->db->execute(
                'UPDATE price_history SET harga = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE wilayah_id = ? AND komoditi_id = ? AND tanggal = ?',
                [$harga, $wilayahId, $komoditiId, $tanggal]
            );
            return 'updated';
        }

        $this->db->execute(
            'INSERT INTO price_history (wilayah_id, komoditi_id, tanggal, harga) VALUES (?, ?, ?, ?)',
            [$wilayahId, $komoditiId, $tanggal, $harga]
        );
        return 'inserted';
    }
}
