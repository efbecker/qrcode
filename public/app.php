<?php
/**
 * QR Code Fácil · front controller
 * O Apache envia para cá toda requisição que não corresponde a um arquivo
 * estático (contas, painel, admin, API e links rastreados /r/<código>).
 */
declare(strict_types=1);

// Servidor embutido do PHP (desenvolvimento): php -S localhost:8080 -t public public/app.php
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path === '/' || ($path !== '/app.php' && is_file(__DIR__ . $path))) {
        return false;
    }
    $_SERVER['SCRIPT_NAME'] = '/app.php';
}

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/routes.php';
