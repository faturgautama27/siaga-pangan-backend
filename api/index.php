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
$app->post('/api/auth/login', function ($request, $response) {
    return AuthController::create()->login($request, $response);
});

// Upload Excel
$app->post('/api/upload', function ($request, $response) {
    return UploadController::create()->upload($request, $response);
})
    ->add(new RoleMiddleware(['admin', 'operator']))
    ->add(new AuthMiddleware());
$app->get('/api/upload-log', function ($request, $response) {
    return UploadController::create()->log($request, $response);
})
    ->add(new RoleMiddleware(['admin', 'operator']))
    ->add(new AuthMiddleware());

// Data master (dropdown)
$app->get('/api/wilayah', function ($request, $response) {
    return HargaHarianController::create()->wilayah($request, $response);
});
$app->get('/api/komoditi', function ($request, $response) {
    return HargaHarianController::create()->komoditi($request, $response);
});

// Fitur utama — semua authenticated
$app->get('/api/harga-harian', function ($request, $response) {
    return HargaHarianController::create()->index($request, $response);
})->add(new AuthMiddleware());

$app->get('/api/grafik', function ($request, $response) {
    return GrafikController::create()->index($request, $response);
})->add(new AuthMiddleware());

$app->get('/api/ihk', function ($request, $response) {
    return IhkController::create()->compare($request, $response);
})->add(new AuthMiddleware());

$app->get('/api/analisa-provinsi', function ($request, $response) {
    return AnalisaProvinsiController::create()->index($request, $response);
})->add(new AuthMiddleware());

$app->get('/api/ews', function ($request, $response) {
    return EwsController::create()->index($request, $response);
})->add(new AuthMiddleware());

$app->post('/api/laporan-koordinasi', function ($request, $response) {
    return LaporanKoordinasiController::create()->store($request, $response);
})
    ->add(new RoleMiddleware(['admin', 'pic', 'koordinator']))
    ->add(new AuthMiddleware());

$app->get('/api/laporan-koordinasi', function ($request, $response) {
    return LaporanKoordinasiController::create()->index($request, $response);
})->add(new AuthMiddleware());

$app->get('/api/prognosa-stok', function ($request, $response) {
    return PrognosaController::create()->index($request, $response);
})->add(new AuthMiddleware());

$app->get('/api/pasar', function ($request, $response) {
    return PasarController::create()->index($request, $response);
})->add(new AuthMiddleware());

$app->get('/api/executive-summary', function ($request, $response) {
    return ExecutiveSummaryController::create()->index($request, $response);
})->add(new AuthMiddleware());

$app->run();
