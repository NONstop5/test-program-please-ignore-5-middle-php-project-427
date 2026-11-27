<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/FlightApiTestCase.php';

final class FlightSearchTest extends FlightApiTestCase
{
    public function testFlightSearchAndDetailsReturnTheSameContractInUtc(): void
    {
        $this->initializeFlightFixtures();
        $response = $this->flightRequest('/api/flights', [
            'origin' => 'MOW', 'destination' => 'LED', 'date' => '2030-01-15',
        ]);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $flights = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $flights);
        $id = (string) $this->db->query("SELECT id FROM flights WHERE flight_number = 'SU1002'")->fetchColumn();
        self::assertSame([
            'id' => $id,
            'flightNumber' => 'SU1002',
            'airline' => ['code' => 'SU', 'name' => 'Аэрофлот'],
            'origin' => ['code' => 'MOW', 'name' => 'Москва', 'country' => 'Россия'],
            'destination' => ['code' => 'LED', 'name' => 'Санкт-Петербург', 'country' => 'Россия'],
            'departureAt' => '2030-01-15T00:00:00.000000Z',
            'arrivalAt' => '2030-01-15T02:00:00.000000Z',
            'durationMinutes' => 120,
            'price' => ['amount' => 5000, 'currency' => 'RUB'],
            'seatsAvailable' => 1,
        ], $flights[0]);
        self::assertSame('SU1003', $flights[1]['flightNumber']);
        self::assertSame('2030-01-15T23:59:59.999999Z', $flights[1]['departureAt']);
        self::assertSame('2030-01-16T01:59:59.999999Z', $flights[1]['arrivalAt']);

        foreach ($flights as $flight) {
            $details = $this->flightRequest('/api/flights/' . $flight['id']);
            self::assertSame(200, $details->getStatusCode());
            self::assertSame($flight, json_decode((string) $details->getBody(), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    #[DataProvider('passengerCounts')]
    public function testFlightSearchFiltersAvailableSeats(string $passengers, array $expected): void
    {
        $this->initializeFlightFixtures();
        $response = $this->flightRequest('/api/flights', [
            'origin' => 'MOW', 'destination' => 'LED', 'date' => '2030-01-15', 'passengers' => $passengers,
        ]);
        self::assertSame(200, $response->getStatusCode());
        $flights = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, array_column($flights, 'flightNumber'));
    }

    public static function passengerCounts(): array
    {
        return [
            'one passenger' => ['1', ['SU1002', 'SU1003']],
            'exact seat count' => ['2', ['SU1003']],
            'leading zeroes' => ['0002', ['SU1003']],
            'not enough seats' => ['3', []],
            'maximum seat count' => ['2147483647', []],
            'over integer limit' => ['2147483648', []],
            'very large number' => ['99999999999999999999999999999999', []],
        ];
    }

    #[DataProvider('emptyFlightSearches')]
    public function testFlightSearchReturnsAnEmptyArray(array $params): void
    {
        $this->initializeFlightFixtures();
        $response = $this->flightRequest('/api/flights', $params);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function emptyFlightSearches(): array
    {
        return [
            'same city' => [['origin' => 'MOW', 'destination' => 'MOW', 'date' => '2030-01-15']],
            'unknown city' => [['origin' => 'XXX', 'destination' => 'LED', 'date' => '2030-01-15']],
            'no flights on date' => [['origin' => 'MOW', 'destination' => 'LED', 'date' => '2030-01-17']],
            'sql injection' => [['origin' => "MOW' OR 1=1 --", 'destination' => 'LED', 'date' => '2030-01-15']],
        ];
    }

    #[DataProvider('invalidFlightSearches')]
    public function testInvalidFlightSearchReturnsValidationError(array $params, string $field): void
    {
        $response = $this->flightRequest('/api/flights', $params);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $error = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('validation_error', $error['code']);
        self::assertStringContainsStringIgnoringCase($field, $error['message']);
    }

    public static function invalidFlightSearches(): array
    {
        $valid = ['origin' => 'MOW', 'destination' => 'LED', 'date' => '2030-01-15'];

        return [
            'missing origin' => [['destination' => 'LED', 'date' => '2030-01-15'], 'origin'],
            'missing destination' => [['origin' => 'MOW', 'date' => '2030-01-15'], 'destination'],
            'missing date' => [['origin' => 'MOW', 'destination' => 'LED'], 'date'],
            'empty origin' => [array_replace($valid, ['origin' => '']), 'origin'],
            'blank destination' => [array_replace($valid, ['destination' => '  ']), 'destination'],
            'array origin' => [array_replace($valid, ['origin' => ['MOW']]), 'origin'],
            'null byte in origin' => [array_replace($valid, ['origin' => "MOW\0"]), 'origin'],
            'array date' => [array_replace($valid, ['date' => ['2030-01-15']]), 'date'],
            'invalid date format' => [array_replace($valid, ['date' => '2030-1-15']), 'date'],
            'timestamp instead of date' => [array_replace($valid, ['date' => '2030-01-15T00:00:00Z']), 'date'],
            'invalid calendar date' => [array_replace($valid, ['date' => '2030-02-29']), 'date'],
            'zero year' => [array_replace($valid, ['date' => '0000-01-01']), 'date'],
            'empty passengers' => [array_replace($valid, ['passengers' => '']), 'passengers'],
            'zero passengers' => [array_replace($valid, ['passengers' => '0']), 'passengers'],
            'negative passengers' => [array_replace($valid, ['passengers' => '-1']), 'passengers'],
            'fractional passengers' => [array_replace($valid, ['passengers' => '1.5']), 'passengers'],
            'nonnumeric passengers' => [array_replace($valid, ['passengers' => 'abc']), 'passengers'],
            'array passengers' => [array_replace($valid, ['passengers' => ['1']]), 'passengers'],
        ];
    }
}
