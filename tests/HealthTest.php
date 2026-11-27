<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HealthTest extends TestCase
{
    public function testHealth(): void
    {
        $app = Application::create('test', new NullLogger());
        $app->getContainer()->set(PDO::class, static function (): never {
            throw new RuntimeException('The health check must not access the database.');
        });

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/health');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^application\/json(?:\s*;|$)/i', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($body);
        self::assertArrayHasKey('status', $body);
        self::assertSame('ok', $body['status']);
    }
}
