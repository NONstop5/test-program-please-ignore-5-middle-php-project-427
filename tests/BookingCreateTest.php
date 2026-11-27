<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/BookingApiTestCase.php';

final class BookingCreateTest extends BookingApiTestCase
{
    public function testBookingWithSinglePassengerIsCreated(): void
    {
        $this->initializeFlightFixtures();
        $flightId = $this->fixtureFlightId('SU1002');

        $response = $this->bookingRequest(self::validPayload($flightId));

        self::assertSame(201, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $booking = $this->decodeJson($response);
        self::assertSame(
            ['code', 'status', 'flight', 'passengers', 'contact', 'totalPrice', 'createdAt'],
            array_keys($booking)
        );
        self::assertMatchesRegularExpression('/\A[A-Z0-9]{6}\z/', $booking['code']);
        self::assertSame('confirmed', $booking['status']);
        self::assertSame([self::validPassenger()], $booking['passengers']);
        self::assertSame(self::validContact(), $booking['contact']);
        self::assertSame(['amount' => 5000, 'currency' => 'RUB'], $booking['totalPrice']);
        self::assertSame($this->decodeJson($this->flightRequest('/api/flights/' . $flightId)), $booking['flight']);
        self::assertCreatedAtIsRecent($booking['createdAt']);

        $statement = $this->db->prepare(
            'SELECT code, status, flight_id, total_price, contact_email, contact_phone, created_at FROM bookings'
        );
        $statement->execute();
        $stored = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertSame($booking['code'], $stored['code']);
        self::assertSame('confirmed', $stored['status']);
        self::assertSame($flightId, (string) $stored['flight_id']);
        self::assertSame(5000, (int) $stored['total_price']);
        self::assertSame('ivan@example.com', $stored['contact_email']);
        self::assertSame('+7 900 000-00-00', $stored['contact_phone']);
        self::assertNotEmpty($stored['created_at']);

        $passengerStatement = $this->db->prepare(
            'SELECT first_name, last_name, date_of_birth, document_number FROM passengers ORDER BY id'
        );
        $passengerStatement->execute();
        self::assertSame([[
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'date_of_birth' => '1990-05-15',
            'document_number' => '1',
        ]], $passengerStatement->fetchAll(PDO::FETCH_ASSOC));

        // Seats are intentionally not decreased on this step.
        $seatStatement = $this->db->prepare('SELECT available_seats FROM flights WHERE flight_number = :number');
        $seatStatement->execute(['number' => 'SU1002']);
        self::assertSame(1, (int) $seatStatement->fetchColumn());
    }

    #[DataProvider('passengerLists')]
    public function testTotalPriceIsCalculatedByServerFromFlightPrice(int $count, int $expectedTotal): void
    {
        $this->initializeFlightFixtures();
        $flightId = $this->fixtureFlightId('SU1002');

        $passengers = [];
        for ($number = 1; $number <= $count; $number++) {
            $passengers[] = self::validPassenger('Имя' . $number, 'Фамилия' . $number, (string) $number);
        }

        $payload = self::validPayload($flightId, $passengers);
        // A price sent by the client must never be used.
        $payload['totalPrice'] = ['amount' => 1, 'currency' => 'RUB'];

        $response = $this->bookingRequest($payload);

        self::assertSame(201, $response->getStatusCode());
        $booking = $this->decodeJson($response);
        self::assertSame(['amount' => $expectedTotal, 'currency' => 'RUB'], $booking['totalPrice']);
        self::assertSame($passengers, $booking['passengers']);
        self::assertSame($count, $this->storePassengerCount());
        self::assertSame($expectedTotal, (int) $this->db->query('SELECT total_price FROM bookings')->fetchColumn());
    }

    public static function passengerLists(): array
    {
        return [
            'one passenger' => [1, 5000],
            'two passengers double the price' => [2, 10000],
            'three passengers triple the price' => [3, 15000],
        ];
    }

    public function testBookingCodesAreUniqueAcrossRequests(): void
    {
        $this->initializeFlightFixtures();
        $flightId = $this->fixtureFlightId('SU1002');
        $codes = [];

        for ($request = 0; $request < 25; $request++) {
            $response = $this->bookingRequest(self::validPayload($flightId));
            self::assertSame(201, $response->getStatusCode());
            $codes[] = $this->decodeJson($response)['code'];
        }

        self::assertSame(array_values(array_unique($codes)), $codes);
        self::assertCount(25, $codes);
        self::assertSame(25, $this->storeBookingCount());
        self::assertSame(
            25,
            (int) $this->db->query('SELECT COUNT(DISTINCT code) FROM bookings')->fetchColumn()
        );
    }

    #[DataProvider('invalidBookings')]
    public function testInvalidBookingReturnsValidationError(Closure $payload, string $field): void
    {
        $this->initializeFlightFixtures();
        $flightId = $this->fixtureFlightId('SU1002');

        $response = $this->bookingRequest($payload($flightId));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $error = $this->decodeJson($response);
        self::assertSame('validation_error', $error['code']);
        self::assertStringContainsStringIgnoringCase($field, $error['message']);
        self::assertSame(0, $this->storeBookingCount());
        self::assertSame(0, $this->storePassengerCount());
    }

    public static function invalidBookings(): array
    {
        $withoutKey = static fn(array $payload, string $key): array => array_diff_key($payload, [$key => null]);

        $withPassenger = static fn(array $passenger): Closure => static fn(string $flightId): array => self::validPayload($flightId, [$passenger]);

        $brokenPassenger = static function (string $key, mixed $value) use ($withPassenger): Closure {
            $passenger = self::validPassenger();
            unset($passenger[$key]);
            if ($value !== null) {
                $passenger[$key] = $value;
            }

            return $withPassenger($passenger);
        };

        $withContact = static fn(array $contact): Closure => static function (string $flightId) use ($contact): array {
            $payload = self::validPayload($flightId);
            $payload['contact'] = $contact;

            return $payload;
        };

        return [
            'missing body' => [
                static fn(string $flightId): ?array => null,
                'body',
            ],
            'malformed json' => [
                static fn(string $flightId): string => '{"flightId":',
                'body',
            ],
            'json array instead of object' => [
                static fn(string $flightId): string => '[1,2]',
                'flightId',
            ],
            'missing flightId' => [
                static fn(string $flightId): array => $withoutKey(self::validPayload($flightId), 'flightId'),
                'flightId',
            ],
            'empty flightId' => [
                static function (string $flightId): array {
                    $payload = self::validPayload($flightId);
                    $payload['flightId'] = '';

                    return $payload;
                },
                'flightId',
            ],
            'blank flightId' => [
                static function (string $flightId): array {
                    $payload = self::validPayload($flightId);
                    $payload['flightId'] = '   ';

                    return $payload;
                },
                'flightId',
            ],
            'unknown flight' => [
                static fn(string $flightId): array => self::validPayload('999999'),
                'flightId',
            ],
            'flight number instead of id' => [
                static fn(string $flightId): array => self::validPayload('SU1002'),
                'flightId',
            ],
            'missing contact' => [
                static fn(string $flightId): array => $withoutKey(self::validPayload($flightId), 'contact'),
                'contact',
            ],
            'contact as string' => [
                static function (string $flightId): array {
                    $payload = self::validPayload($flightId);
                    $payload['contact'] = 'ivan@example.com';

                    return $payload;
                },
                'contact',
            ],
            'missing contact email' => [
                $withContact(['phone' => '+7 900 000-00-00']),
                'contact.email',
            ],
            'empty contact email' => [
                $withContact(['email' => '', 'phone' => '+7 900 000-00-00']),
                'contact.email',
            ],
            'blank contact phone' => [
                $withContact(['email' => 'ivan@example.com', 'phone' => '  ']),
                'contact.phone',
            ],
            'contact phone longer than column' => [
                $withContact(['email' => 'ivan@example.com', 'phone' => str_repeat('9', 51)]),
                'contact.phone',
            ],
            'missing passengers' => [
                static fn(string $flightId): array => $withoutKey(self::validPayload($flightId), 'passengers'),
                'passengers',
            ],
            'empty passengers' => [
                static fn(string $flightId): array => self::validPayload($flightId, []),
                'passengers',
            ],
            'passenger as string' => [
                static fn(string $flightId): array => self::validPayload($flightId, ['Иван']),
                'passengers[0]',
            ],
            'missing first name' => [
                $brokenPassenger('firstName', null),
                'passengers[0].firstName',
            ],
            'blank last name' => [
                $brokenPassenger('lastName', '   '),
                'passengers[0].lastName',
            ],
            'missing date of birth' => [
                $brokenPassenger('dateOfBirth', null),
                'passengers[0].dateOfBirth',
            ],
            'date of birth with dots' => [
                $brokenPassenger('dateOfBirth', '15.05.1990'),
                'passengers[0].dateOfBirth',
            ],
            'impossible date of birth' => [
                $brokenPassenger('dateOfBirth', '1990-02-30'),
                'passengers[0].dateOfBirth',
            ],
            'missing document number' => [
                $brokenPassenger('documentNumber', null),
                'passengers[0].documentNumber',
            ],
            'null byte in document number' => [
                $brokenPassenger('documentNumber', "1\0"),
                'passengers[0].documentNumber',
            ],
            'second passenger invalid' => [
                static function (string $flightId): array {
                    $second = self::validPassenger('Пётр', 'Иванов', '2');
                    unset($second['lastName']);

                    return self::validPayload($flightId, [self::validPassenger(), $second]);
                },
                'passengers[1].lastName',
            ],
        ];
    }

    private static function assertCreatedAtIsRecent(string $createdAt): void
    {
        $parsed = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.u\Z',
            $createdAt,
            new DateTimeZone('UTC')
        );
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);
        self::assertLessThanOrEqual(60, abs(time() - $parsed->getTimestamp()));
    }
}
