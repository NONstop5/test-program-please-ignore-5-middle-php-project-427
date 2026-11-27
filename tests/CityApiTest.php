<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use PDO;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

require_once __DIR__ . '/DatabaseTestCase.php';

final class CityApiTest extends DatabaseTestCase
{
    public function testCitiesList(): void
    {
        $this->initializeDatabase();
        $app = Application::create('test', new NullLogger());
        $app->getContainer()->set(PDO::class, $this->db);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/cities');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame([
            ['code' => 'MOW', 'name' => 'Москва', 'country' => 'Россия'],
            ['code' => 'LED', 'name' => 'Санкт-Петербург', 'country' => 'Россия'],
            ['code' => 'AER', 'name' => 'Сочи', 'country' => 'Россия'],
            ['code' => 'KZN', 'name' => 'Казань', 'country' => 'Россия'],
            ['code' => 'SVX', 'name' => 'Екатеринбург', 'country' => 'Россия'],
            ['code' => 'OVB', 'name' => 'Новосибирск', 'country' => 'Россия'],
            ['code' => 'KGD', 'name' => 'Калининград', 'country' => 'Россия'],
        ], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }
}
