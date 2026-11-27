<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/FlightApiTestCase.php';

final class FlightDetailsTest extends FlightApiTestCase
{
    public function testSoldOutFlightCanStillBeRetrievedById(): void
    {
        $this->initializeFlightFixtures();
        $id = $this->db->query("SELECT id FROM flights WHERE flight_number = 'SU1005'")->fetchColumn();
        $response = $this->flightRequest('/api/flights/' . $id);
        self::assertSame(200, $response->getStatusCode());
        $flight = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $flight['seatsAvailable']);
    }

    #[DataProvider('unknownFlightIds')]
    public function testUnknownFlightReturnsNotFound(string $id): void
    {
        $this->initializeFlightFixtures();
        $response = $this->flightRequest('/api/flights/' . $id);
        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $error = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('not_found', $error['code']);
        self::assertNotEmpty($error['message']);
    }

    public static function unknownFlightIds(): array
    {
        return [
            'nonnumeric' => ['NOPE'],
            'zero' => ['0'],
            'negative' => ['-1'],
            'not in database' => ['999999'],
            'beyond bigint' => ['9223372036854775808'],
            'very large' => ['99999999999999999999999999999999'],
        ];
    }
}
