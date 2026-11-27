<?php

declare(strict_types=1);

namespace App;

use App\Handlers\HttpErrorHandler;
use DI\Container;
use GuzzleHttp\Client;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;

final class Application
{
    /**
     * @return App<Container>
     */
    public static function create(string $appEnv = 'prod', ?LoggerInterface $logger = null): App
    {
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
        $httpErrorHandler = new HttpErrorHandler($app->getCallableResolver(), $app->getResponseFactory(), $logger);

        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware($appEnv !== 'prod', true, true);
        $errorMiddleware->setDefaultErrorHandler($httpErrorHandler);
        $app->add(MethodOverrideMiddleware::class);

        Routes::init($app);

        return $app;
    }
}
