<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

require_once __DIR__ . '/DatabaseTestCase.php';

abstract class FlightApiTestCase extends DatabaseTestCase
{
    protected function flightRequest(string $path, array $params = []): ResponseInterface
    {
        $app = Application::create('test', new NullLogger());
        $app->getContainer()->set(PDO::class, $this->db);
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)->withQueryParams($params);

        return $app->handle($request);
    }

    protected function initializeFlightFixtures(): void
    {
        $this->initializeDatabase(new DateTimeImmutable('2030-01-15 00:00:00', new DateTimeZone('UTC')));
        $this->db->exec("SET TIME ZONE 'Pacific/Kiritimati'");
        $this->db->exec('DELETE FROM flights');
        $this->db->exec(<<<'SQL'
            INSERT INTO flights (airline_id, flight_number, departure_city_id, arrival_city_id,
                                 departure_at, arrival_at, duration_minutes, price, available_seats)
            SELECT airline.id, fixture.number, origin.id, destination.id,
                   fixture.departure::timestamptz, fixture.departure::timestamptz + INTERVAL '120 minutes',
                   120, 5000, fixture.seats
            FROM (VALUES
                ('SU1001', 'MOW', 'LED', '2030-01-14 23:59:59+00', 4),
                ('SU1002', 'MOW', 'LED', '2030-01-15 00:00:00+00', 1),
                ('SU1003', 'MOW', 'LED', '2030-01-15 23:59:59.999999+00', 2),
                ('SU1004', 'MOW', 'LED', '2030-01-16 00:00:00+00', 4),
                ('SU1005', 'MOW', 'LED', '2030-01-15 12:00:00+00', 0),
                ('SU1006', 'LED', 'MOW', '2030-01-15 13:00:00+00', 4)
            ) AS fixture(number, origin_code, destination_code, departure, seats)
            JOIN airlines airline ON airline.code = 'SU'
            JOIN cities origin ON origin.code = fixture.origin_code
            JOIN cities destination ON destination.code = fixture.destination_code
            SQL);
    }
}
