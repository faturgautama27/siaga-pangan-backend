<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Db\TursoConnection;
use App\Services\ExcelParserService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelParserServiceTest extends TestCase
{
    private MockObject $db;
    private ExcelParserService $service;

    protected function setUp(): void
    {
        $this->db = $this->createMock(TursoConnection::class);
        $this->service = new ExcelParserService($this->db);
    }

    // ─── Helper: buat Excel di memori dan kembalikan bytes ────────────────────

    private function buildExcel(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIdx => $cols) {
            foreach ($cols as $colIdx => $value) {
                // PhpSpreadsheet v2+: gunakan koordinat string (A1, B2, dst)
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
                $sheet->setCellValue($colLetter . ($rowIdx + 1), $value);
            }
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return ob_get_clean();
    }

    // ─── Helper: expose private method via Reflection ────────────────────────

    private function callPrivate(string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod(ExcelParserService::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->service, $args);
    }

    // ─── Tests: parseDateColumns ──────────────────────────────────────────────

    public function testParseDateColumnsWithShortYear(): void
    {
        $header = [null, null, null, '03/08/26', '04/08/26', '14/08/26'];
        $result = $this->callPrivate('parseDateColumns', [$header]);

        $this->assertSame('2026-08-03', $result[3]);
        $this->assertSame('2026-08-04', $result[4]);
        $this->assertSame('2026-08-14', $result[5]);
    }

    public function testParseDateColumnsWithFullYear(): void
    {
        $header = [null, null, null, '03/08/2026'];
        $result = $this->callPrivate('parseDateColumns', [$header]);

        $this->assertSame('2026-08-03', $result[3]);
    }

    public function testParseDateColumnsSkipsFirstThreeColumns(): void
    {
        $header = ['Kode', 'Kabupaten', 'Variant', '03/08/26'];
        $result = $this->callPrivate('parseDateColumns', [$header]);

        $this->assertArrayNotHasKey(0, $result);
        $this->assertArrayNotHasKey(1, $result);
        $this->assertArrayNotHasKey(2, $result);
        $this->assertArrayHasKey(3, $result);
    }

    public function testParseDateColumnsIgnoresNonDateValues(): void
    {
        $header = [null, null, null, 'bukan-tanggal', '', '03/08/26'];
        $result = $this->callPrivate('parseDateColumns', [$header]);

        $this->assertArrayNotHasKey(3, $result);
        $this->assertArrayNotHasKey(4, $result);
        $this->assertArrayHasKey(5, $result);
    }

    // ─── Tests: parseHarga ────────────────────────────────────────────────────

    public function testParseHargaNumericValue(): void
    {
        $result = $this->callPrivate('parseHarga', [31333]);
        $this->assertSame(31333.0, $result);
    }

    public function testParseHargaStringWithCommaThousandsSeparator(): void
    {
        // Format Indonesia: 31,333 = tiga puluh satu ribu tiga ratus tiga puluh tiga
        $result = $this->callPrivate('parseHarga', ['31,333']);
        $this->assertSame(31333.0, $result);
    }

    public function testParseHargaStringWithDotThousandsSeparator(): void
    {
        // Format Indonesia: titik = pemisah ribuan, bukan desimal
        // 31.333 = tiga puluh satu ribu tiga ratus tiga puluh tiga
        $result = $this->callPrivate('parseHarga', ['31.333']);
        $this->assertSame(31333.0, $result);
    }

    public function testParseHargaEmptyStringReturnsNull(): void
    {
        $result = $this->callPrivate('parseHarga', ['']);
        $this->assertNull($result);
    }

    public function testParseHargaNullReturnsNull(): void
    {
        $result = $this->callPrivate('parseHarga', [null]);
        $this->assertNull($result);
    }

    public function testParseHargaNonNumericStringReturnsNull(): void
    {
        $result = $this->callPrivate('parseHarga', ['tidak-valid']);
        $this->assertNull($result);
    }

    public function testParseHargaLargeValue(): void
    {
        $result = $this->callPrivate('parseHarga', ['149,333']);
        $this->assertSame(149333.0, $result);
    }

    // ─── Tests: parse() — full integration with mocked DB ────────────────────

    public function testParseWithValidExcelUpsertsPriceHistory(): void
    {
        $rows = [
            ['Kode Kemendagri', 'Kabupaten Kota', 'Nama Variant', '03/08/26', '04/08/26'],
            ['3301', 'Kab. Cilacap', 'Beras Medium', '13,500', '13,500'],
        ];

        $this->db->expects($this->atLeastOnce())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $args = []) {
                // Resolve wilayah
                if (str_contains($sql, 'master_wilayah')) {
                    return [['id' => '1']];
                }
                // Resolve komoditi
                if (str_contains($sql, 'master_komoditi')) {
                    return [['id' => '5']];
                }
                // Cek existing price_history
                if (str_contains($sql, 'price_history')) {
                    return [];
                }
                return [];
            });

        $this->db->expects($this->atLeastOnce())
            ->method('execute')
            ->willReturn(['affected_rows' => 1, 'last_insert_id' => 1]);

        $content = $this->buildExcel($rows);
        $result  = $this->service->parse($content, 'test.xlsx', 1);

        $this->assertSame(2, $result['inserted']); // 2 tanggal
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertEmpty($result['skip_detail']);
    }

    public function testParseForwardFillsEmptyWilayahCells(): void
    {
        // Baris ke-2 kolom A dan B kosong (merged cell)
        $rows = [
            ['Kode Kemendagri', 'Kabupaten Kota', 'Nama Variant', '03/08/26'],
            ['3301', 'Kab. Cilacap', 'Beras Medium',   '13,500'],
            ['',     '',             'Beras Premium',   '14,900'], // forward-fill
        ];

        $this->db->expects($this->atLeastOnce())
            ->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'master_wilayah')) return [['id' => '1']];
                if (str_contains($sql, 'master_komoditi')) return [['id' => '5']];
                if (str_contains($sql, 'price_history')) return [];
                return [];
            });

        $this->db->expects($this->atLeastOnce())
            ->method('execute')
            ->willReturn(['affected_rows' => 1, 'last_insert_id' => 1]);

        $content = $this->buildExcel($rows);
        $result  = $this->service->parse($content, 'test.xlsx', 1);

        // Kedua baris harus berhasil (forward-fill bekerja)
        $this->assertSame(2, $result['inserted']);
        $this->assertSame(0, $result['skipped']);
    }

    public function testParseSkipsRowWithEmptyHarga(): void
    {
        $rows = [
            ['Kode Kemendagri', 'Kabupaten Kota', 'Nama Variant', '03/08/26'],
            ['3301', 'Kab. Cilacap', 'Beras Medium', ''],  // harga kosong
        ];

        $this->db->expects($this->atLeastOnce())
            ->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'master_wilayah')) return [['id' => '1']];
                if (str_contains($sql, 'master_komoditi')) return [['id' => '5']];
                return [];
            });

        $this->db->expects($this->atLeastOnce())
            ->method('execute')
            ->willReturn(['affected_rows' => 1, 'last_insert_id' => 1]);

        $content = $this->buildExcel($rows);
        $result  = $this->service->parse($content, 'test.xlsx', 1);

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertNotEmpty($result['skip_detail']);
    }

    public function testParseSkipsRowWithUnknownKodeKemendagri(): void
    {
        $rows = [
            ['Kode Kemendagri', 'Kabupaten Kota', 'Nama Variant', '03/08/26'],
            ['9999', 'Kab. Tidak Ada', 'Beras Medium', '13,500'],  // kode tidak ada
        ];

        $this->db->expects($this->atLeastOnce())
            ->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'master_wilayah')) return []; // tidak ditemukan
                return [];
            });

        // execute hanya untuk upload_log
        $this->db->expects($this->atLeastOnce())
            ->method('execute')
            ->willReturn(['affected_rows' => 1, 'last_insert_id' => 1]);

        $content = $this->buildExcel($rows);
        $result  = $this->service->parse($content, 'test.xlsx', 1);

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('9999', $result['skip_detail'][0]['reason']);
    }

    public function testParseAutoInsertsNewKomoditi(): void
    {
        $rows = [
            ['Kode Kemendagri', 'Kabupaten Kota', 'Nama Variant', '03/08/26'],
            ['3301', 'Kab. Cilacap', 'Komoditi Baru XYZ', '25,000'],
        ];

        $komoditiInserted = false;

        $this->db->expects($this->atLeastOnce())
            ->method('query')
            ->willReturnCallback(function (string $sql) use (&$komoditiInserted) {
                if (str_contains($sql, 'master_wilayah')) return [['id' => '1']];
                if (str_contains($sql, 'master_komoditi')) {
                    // Pertama kosong (belum ada), setelah insert ada
                    return $komoditiInserted ? [['id' => '99']] : [];
                }
                if (str_contains($sql, 'price_history')) return [];
                return [];
            });

        $this->db->expects($this->atLeastOnce())
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$komoditiInserted) {
                if (str_contains($sql, 'INSERT INTO master_komoditi')) {
                    $komoditiInserted = true;
                    return ['affected_rows' => 1, 'last_insert_id' => 99];
                }
                return ['affected_rows' => 1, 'last_insert_id' => 1];
            });

        $content = $this->buildExcel($rows);
        $result  = $this->service->parse($content, 'test.xlsx', 1);

        $this->assertTrue($komoditiInserted, 'Komoditi baru seharusnya di-insert otomatis');
    }

    public function testParseThrowsExceptionOnEmptyFile(): void
    {
        $this->expectException(\RuntimeException::class);

        // Buat Excel kosong (tidak ada baris)
        $rows = [[]]; // hanya satu baris kosong
        $content = $this->buildExcel($rows);
        $this->service->parse($content, 'empty.xlsx', 1);
    }

    public function testParseUpdatesExistingPriceHistory(): void
    {
        $rows = [
            ['Kode Kemendagri', 'Kabupaten Kota', 'Nama Variant', '03/08/26'],
            ['3301', 'Kab. Cilacap', 'Beras Medium', '14,000'],
        ];

        $this->db->expects($this->atLeastOnce())
            ->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'master_wilayah')) return [['id' => '1']];
                if (str_contains($sql, 'master_komoditi')) return [['id' => '5']];
                // Sudah ada di price_history → update
                if (str_contains($sql, 'price_history')) return [['id' => '100']];
                return [];
            });

        $this->db->expects($this->atLeastOnce())
            ->method('execute')
            ->willReturn(['affected_rows' => 1, 'last_insert_id' => null]);

        $content = $this->buildExcel($rows);
        $result  = $this->service->parse($content, 'test.xlsx', 1);

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['updated']);
    }
}
