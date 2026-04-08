<?php

declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicPath = __DIR__ . '/public' . $requestPath;

if ($requestPath !== '/' && is_file($publicPath)) {
    return false;
}

if (is_dir($publicPath) && is_file($publicPath . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($publicPath . '/index.html');
    return true;
}

require __DIR__ . '/public/index.php';
