<?php
// Router untuk PHP built-in server development
// Jalankan dengan: php -S localhost:8000 -t api router.php

if (php_sapi_name() === 'cli-server') {
    $url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // Serve static files langsung
    if ($url !== '/' && file_exists(__DIR__ . '/api' . $url)) {
        return false;
    }
}

require __DIR__ . '/api/index.php';
