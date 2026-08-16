<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Db\TursoConnection;
use App\Services\PriceCalculationService;

class PriceCalculationServiceTest extends TestCase
{
    private MockObject $db;
    private PriceCalculationService $service;

    protected function setUp(): void
    {
        $this->db      = $this->createMock(TursoConnection::class);
        $this->service = new PriceCalculationService($this->db);
    }

    // ─── statusPerubahan ──────────────────────────────────────────────────────

    public function testStatusPerubahanNaik(): void
    {
        $result = $this->service->statusPerubahan(15000.0, 13000.0);
        $this->assertSame('Naik', $result['status']);
        $this->assertSame(2000.0, $result['selisih']);
    }

    public function testStatusPerubahanTurun(): void
    {
        $result = $this->service->statusPerubahan(12000.0, 15000.0);
        $this->assertSame('Turun', $result['status']);
        $this->assertSame(-3000.0, $result['selisih']);
    }

    public function testStatusPerubahanTetap(): void
    {
        $result = $this->service->statusPerubahan(13500.0, 13500.0);
        $this->assertSame('Tetap', $result['status']);
        $this->assertSame(0.0, $result['selisih']);
    }

    // ─── statusHetHap ─────────────────────────────────────────────────────────

    public function testStatusHetHapTidakAdaReferensi(): void
    {
        $result = $this->service->statusHetHap(15000.0, null, null, null, null);
        $this->assertSame('Tidak Ada HET/HAP', $result);
    }

    public function testStatusHetHapSamaHET(): void
    {
        $result = $this->service->statusHetHap(17500.0, 17500.0, null, null, null);
        $this->assertSame('Sama dengan HET', $result);
    }

    public function testStatusHetHapDiAtasHET(): void
    {
        $result = $this->service->statusHetHap(18000.0, 17500.0, null, null, null);
        $this->assertSame('Di Atas HET', $result);
    }

    public function testStatusHetHapDiBawahHET(): void
    {
        $result = $this->service->statusHetHap(16000.0, 17500.0, null, null, null);
        $this->assertSame('Di Bawah HET', $result);
    }

    public function testStatusHetHapSamaHAP(): void
    {
        $result = $this->service->statusHetHap(13500.0, null, 13500.0, null, null);
        $this->assertSame('Sama dengan HAP', $result);
    }

    public function testStatusHetHapDiAtasHAP(): void
    {
        $result = $this->service->statusHetHap(14000.0, null, 13500.0, null, null);
        $this->assertSame('Di Atas HAP', $result);
    }

    public function testStatusHetHapDiBawahHAP(): void
    {
        $result = $this->service->statusHetHap(12000.0, null, 13500.0, null, null);
        $this->assertSame('Di Bawah HAP', $result);
    }

    public function testStatusHetHapDiBawahHAPMin(): void
    {
        $result = $this->service->statusHetHap(11000.0, null, null, 12500.0, 13500.0);
        $this->assertSame('Di Bawah HAP Min', $result);
    }

    public function testStatusHetHapDiAtasHAPMaks(): void
    {
        $result = $this->service->statusHetHap(14000.0, null, null, 12500.0, 13500.0);
        $this->assertSame('Di Atas HAP Maks', $result);
    }

    public function testStatusHetHapDiAntaraHAP(): void
    {
        $result = $this->service->statusHetHap(13000.0, null, null, 12500.0, 13500.0);
        $this->assertSame('Di Antara HAP', $result);
    }

    public function testStatusHetHapPadaBatasMin(): void
    {
        $result = $this->service->statusHetHap(12500.0, null, null, 12500.0, 13500.0);
        $this->assertSame('Di Antara HAP', $result);
    }

    public function testStatusHetHapPadaBatasMaks(): void
    {
        $result = $this->service->statusHetHap(13500.0, null, null, 12500.0, 13500.0);
        $this->assertSame('Di Antara HAP', $result);
    }

    // ─── hitungGap ────────────────────────────────────────────────────────────

    public function testHitungGapSemuaNull(): void
    {
        $result = $this->service->hitungGap(15000.0, null, null, null);
        $this->assertNull($result['gap_het']);
        $this->assertNull($result['gap_hap']);
        $this->assertNull($result['gap_rata_provinsi']);
    }

    public function testHitungGapDenganHET(): void
    {
        $result = $this->service->hitungGap(18000.0, 17500.0, null, null);
        $this->assertSame(500.0, $result['gap_het']);
        $this->assertNull($result['gap_hap']);
    }

    public function testHitungGapDenganHAP(): void
    {
        $result = $this->service->hitungGap(14000.0, null, 13500.0, null);
        $this->assertNull($result['gap_het']);
        $this->assertSame(500.0, $result['gap_hap']);
    }

    public function testHitungGapDenganRataProvinsi(): void
    {
        $result = $this->service->hitungGap(15000.0, null, null, 13000.0);
        $this->assertSame(2000.0, $result['gap_rata_provinsi']);
    }

    public function testHitungGapNegatif(): void
    {
        $result = $this->service->hitungGap(12000.0, 17500.0, 13500.0, 14000.0);
        $this->assertSame(-5500.0, $result['gap_het']);
        $this->assertSame(-1500.0, $result['gap_hap']);
        $this->assertSame(-2000.0, $result['gap_rata_provinsi']);
    }

    public function testHitungGapDibulatkan(): void
    {
        $result = $this->service->hitungGap(15333.33, 15000.0, null, null);
        $this->assertSame(333.33, $result['gap_het']);
    }

    // ─── analisaProvinsi ──────────────────────────────────────────────────────

    public function testAnalisaProvinsiKosongBilaNoData(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'price_history')) return [];
                return [];
            });

        $result = $this->service->analisaProvinsi(5, '2024-01-01');
        $this->assertEmpty($result);
    }

    public function testAnalisaProvinsiReturnsSortedByHarga(): void
    {
        $prices = [
            ['harga' => '15000', 'wilayah_id' => '1', 'wilayah' => 'Kab. A', 'kode_kemendagri' => '3301'],
            ['harga' => '13000', 'wilayah_id' => '2', 'wilayah' => 'Kab. B', 'kode_kemendagri' => '3302'],
            ['harga' => '14000', 'wilayah_id' => '3', 'wilayah' => 'Kab. C', 'kode_kemendagri' => '3303'],
        ];

        $this->db->expects($this->atLeastOnce())
            ->method('query')
            ->willReturnCallback(function (string $sql) use ($prices) {
                if (str_contains($sql, 'price_history') && str_contains($sql, 'ORDER BY ph.harga DESC')) return $prices;
                if (str_contains($sql, 'referensi_het_hap')) return []; // no reference
                return [];
            });

        $result = $this->service->analisaProvinsi(5, '2024-01-01');

        $this->assertCount(3, $result);
        // Harga tertinggi pertama
        $this->assertSame(15000.0, $result[0]['harga']);
        // Rata-rata provinsi = (15000+13000+14000)/3 = 14000
        $this->assertSame(14000.0, $result[0]['rata_provinsi']);
    }

    public function testAnalisaProvinsiStatusProvinsiDiAtas(): void
    {
        $prices = [
            ['harga' => '16000', 'wilayah_id' => '1', 'wilayah' => 'Kab. A', 'kode_kemendagri' => '3301'],
            ['harga' => '12000', 'wilayah_id' => '2', 'wilayah' => 'Kab. B', 'kode_kemendagri' => '3302'],
        ];

        $this->db->expects($this->atLeastOnce())
            ->method('query')
            ->willReturnCallback(function (string $sql) use ($prices) {
                if (str_contains($sql, 'price_history') && str_contains($sql, 'ORDER BY ph.harga DESC')) return $prices;
                if (str_contains($sql, 'referensi_het_hap')) return [];
                return [];
            });

        $result = $this->service->analisaProvinsi(5, '2024-01-01');

        // Rata-rata = (16000+12000)/2 = 14000
        $this->assertSame('Di Atas Prov', $result[0]['status_provinsi']);   // 16000 > 14000
        $this->assertSame('Di Bawah Prov', $result[1]['status_provinsi']);  // 12000 < 14000
    }

    public function testAnalisaProvinsiWithHETReference(): void
    {
        $prices = [
            ['harga' => '18000', 'wilayah_id' => '1', 'wilayah' => 'Kab. A', 'kode_kemendagri' => '3301'],
        ];

        $this->db->expects($this->atLeastOnce())
            ->method('query')
            ->willReturnCallback(function (string $sql) use ($prices) {
                if (str_contains($sql, 'price_history') && str_contains($sql, 'ORDER BY ph.harga DESC')) return $prices;
                if (str_contains($sql, 'referensi_het_hap')) {
                    return [['het' => '17500', 'hap' => null, 'hap_minimal' => null, 'hap_maksimal' => null]];
                }
                return [];
            });

        $result = $this->service->analisaProvinsi(19, '2024-01-01');

        $this->assertSame('Di Atas HET', $result[0]['status_het_hap']);
        $this->assertSame(500.0, $result[0]['gap_het']);
    }
}
