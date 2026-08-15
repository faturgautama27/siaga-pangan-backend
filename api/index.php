<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use App\Config\Settings;
use App\Middleware\CorsMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Controllers\AuthController;
use App\Controllers\UploadController;
use App\Controllers\HargaHarianController;
use App\Controllers\GrafikController;
use App\Controllers\IhkController;
use App\Controllers\AnalisaProvinsiController;
use App\Controllers\EwsController;
use App\Controllers\LaporanKoordinasiController;
use App\Controllers\PrognosaController;
use App\Controllers\PasarController;
use App\Controllers\ExecutiveSummaryController;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables dari .env bila ada (lokal)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

$app = AppFactory::create();

// Error handling — sembunyikan detail di production
$app->addErrorMiddleware(
    displayErrorDetails: ($_ENV['APP_ENV'] ?? 'production') === 'development',
    logErrors: true,
    logErrorDetails: true
);

$app->addRoutingMiddleware();

// CORS harus paling awal
$app->add(new CorsMiddleware());

// Routes
$app->post('/api/auth/login', [AuthController::class, 'login']);

// Upload Excel
$app->post('/api/upload', [UploadController::class, 'upload'])
    ->add(new RoleMiddleware(['admin', 'operator']))
    ->add(new AuthMiddleware());
$app->get('/api/upload-log', [UploadController::class, 'log'])
    ->add(new RoleMiddleware(['admin', 'operator']))
    ->add(new AuthMiddleware());

// Data master (dropdown)
$app->get('/api/wilayah', [HargaHarianController::class, 'wilayah']);
$app->get('/api/komoditi', [HargaHarianController::class, 'komoditi']);

// Fitur utama — semua authenticated
$app->get('/api/harga-harian', [HargaHarianController::class, 'index'])
    ->add(new AuthMiddleware());
$app->get('/api/grafik', [GrafikController::class, 'index'])
    ->add(new AuthMiddleware());
$app->get('/api/ihk', [IhkController::class, 'compare'])
    ->add(new AuthMiddleware());
$app->get('/api/analisa-provinsi', [AnalisaProvinsiController::class, 'index'])
    ->add(new AuthMiddleware());
$app->get('/api/ews', [EwsController::class, 'index'])
    ->add(new AuthMiddleware());
$app->post('/api/laporan-koordinasi', [LaporanKoordinasiController::class, 'store'])
    ->add(new RoleMiddleware(['admin', 'pic', 'koordinator']))
    ->add(new AuthMiddleware());
$app->get('/api/laporan-koordinasi', [LaporanKoordinasiController::class, 'index'])
    ->add(new AuthMiddleware());
$app->get('/api/prognosa-stok', [PrognosaController::class, 'index'])
    ->add(new AuthMiddleware());
$app->get('/api/pasar', [PasarController::class, 'index'])
    ->add(new AuthMiddleware());
$app->get('/api/executive-summary', [ExecutiveSummaryController::class, 'index'])
    ->add(new AuthMiddleware());

$app->run();
