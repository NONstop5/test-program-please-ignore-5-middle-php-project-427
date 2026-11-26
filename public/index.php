<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Connection;
use App\Handlers\HttpErrorHandler;
use App\Routes;
use DI\Container;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;

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

$container = new Container();

$container->set(PDO::class, static function (): PDO {
    $databaseUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
    if (!is_string($databaseUrl) || $databaseUrl === '') {
        throw new RuntimeException('DATABASE_URL must be set');
    }

    return Connection::create($databaseUrl);
});
$container->set(Client::class, fn() => new Client(['timeout' => 2.0]));

$app = AppFactory::createFromContainer($container);

$callableResolver = $app->getCallableResolver();
$responseFactory = $app->getResponseFactory();

// Определяем пользовательский обработчик ошибок
$httpErrorHandler = new HttpErrorHandler($callableResolver, $responseFactory);

// Добавление промежуточного ПО маршрутизации
$app->addRoutingMiddleware();

// Добавляем промежуточное ПО обработки ошибок
$errorMiddleware = $app->addErrorMiddleware($appEnv !== 'prod', true, true);
$errorMiddleware->setDefaultErrorHandler($httpErrorHandler);

$app->add(MethodOverrideMiddleware::class);

Routes::init($app);

$app->run();
