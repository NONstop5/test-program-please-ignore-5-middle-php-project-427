<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Application;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';

// public/index.php, до подключения фреймворка
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$isApiRequest = $path === '/api' || str_starts_with($path, '/api/');
$filePath = realpath(__DIR__ . $path);

if (
    PHP_SAPI === 'cli-server'
    && !$isApiRequest
    && $path !== '/'
    && $path !== '/index.php'
    && $filePath !== false
    && str_starts_with($filePath, __DIR__ . DIRECTORY_SEPARATOR)
    && is_file($filePath)
) {
    return false; // сервер отдаст файл как есть
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (($method === 'GET' || $method === 'HEAD') && !$isApiRequest) {
    $frontend = __DIR__ . '/index.html';
    if (is_file($frontend)) {
        header('Content-Type: text/html; charset=UTF-8');
        if ($method === 'GET') {
            readfile($frontend);
        }
        return;
    }
}

// Старт PHP сессии
session_start();

Application::create($appEnv)->run();
