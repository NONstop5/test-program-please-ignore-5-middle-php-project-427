<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/BookingApiTestCase.php';

final class BookingCancelTest extends BookingApiTestCase
{
    public function testCancelChangesStatusAndReturnsTheBooking(): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();
        $code = (string) $created['code'];

        $response = $this->cancelBookingRequest($code, ['lastName' => 'Петров']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $cancelled = $this->decodeJson($response);
        self::assertSame($created['code'], $cancelled['code']);
        self::assertSame('cancelled', $cancelled['status']);

        // Everything except the status must stay intact: the frontend redraws the whole card.
        $expected = $created;
        $expected['status'] = 'cancelled';
        self::assertSame($expected, $cancelled);

        self::assertSame('cancelled', $this->storeBookingStatus($code));
        // Cancellation never deletes the booking.
        self::assertSame(1, $this->storeBookingCount());
    }

    #[DataProvider('equivalentLastNames')]
    public function testCancelMatchesLastNameIgnoringCaseAndWhitespace(string $lastName): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();

        $response = $this->cancelBookingRequest((string) $created['code'], ['lastName' => $lastName]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('cancelled', $this->decodeJson($response)['status']);
        self::assertSame('cancelled', $this->storeBookingStatus((string) $created['code']));
    }

    public static function equivalentLastNames(): array
    {
        return [
            'exact match' => ['Петров'],
            'lower case' => ['петров'],
            'upper case' => ['ПЕТРОВ'],
            'mixed case' => ['пЕтРоВ'],
            'surrounding whitespace' => ["  Петров\t"],
        ];
    }

    public function testCancelMatchesAnyPassengerLastName(): void
    {
        $this->initializeFlightFixtures();
        $passengers = [
            self::validPassenger('Иван', 'Петров', '1'),
            self::validPassenger('Пётр', 'Иванов', '2'),
        ];
        $created = $this->createBooking($passengers);

        $response = $this->cancelBookingRequest((string) $created['code'], ['lastName' => 'Иванов']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('cancelled', $this->decodeJson($response)['status']);
    }

    public function testCancelMatchesCodeIgnoringCase(): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();

        $response = $this->cancelBookingRequest(strtolower((string) $created['code']), ['lastName' => 'Петров']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('cancelled', $this->decodeJson($response)['status']);
    }

    #[DataProvider('mismatchedLastNames')]
    public function testCancelWithWrongOrMissingLastNameReturnsNotFound(array|string|null $payload): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();
        $code = (string) $created['code'];

        $response = $this->cancelBookingRequest($code, $payload);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $error = $this->decodeJson($response);
        self::assertSame(['code', 'message'], array_keys($error));
        self::assertSame('not_found', $error['code']);
        self::assertNotSame('', $error['message']);

        // A failed cancellation must never touch the booking.
        self::assertSame('confirmed', $this->storeBookingStatus($code));
    }

    public static function mismatchedLastNames(): array
    {
        return [
            'wrong last name' => [['lastName' => 'Сидоров']],
            'missing last name' => [[]],
            'empty last name' => [['lastName' => '']],
            'blank last name' => [['lastName' => '   ']],
            'no body' => [null],
        ];
    }

    public function testCancelUnknownCodeReturnsNotFound(): void
    {
        $this->initializeFlightFixtures();
        $this->createBooking();

        $response = $this->cancelBookingRequest('ZZZZZZ', ['lastName' => 'Петров']);

        self::assertSame(404, $response->getStatusCode());
        $error = $this->decodeJson($response);
        self::assertSame('not_found', $error['code']);
        self::assertNotSame('', $error['message']);
    }

    public function testAllCancelFailuresShareTheSameResponseBody(): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();
        $code = (string) $created['code'];

        $unknownCode = $this->decodeJson($this->cancelBookingRequest('ZZZZZZ', ['lastName' => 'Петров']));
        $wrongLastName = $this->decodeJson($this->cancelBookingRequest($code, ['lastName' => 'Сидоров']));
        $missingLastName = $this->decodeJson($this->cancelBookingRequest($code, []));

        self::assertSame('not_found', $unknownCode['code']);
        self::assertSame($unknownCode, $wrongLastName);
        self::assertSame($unknownCode, $missingLastName);
    }

    public function testRepeatedCancelSucceedsAndKeepsCancelledStatus(): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();
        $code = (string) $created['code'];

        $first = $this->cancelBookingRequest($code, ['lastName' => 'Петров']);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('cancelled', $this->decodeJson($first)['status']);

        $second = $this->cancelBookingRequest($code, ['lastName' => 'Петров']);
        self::assertSame(200, $second->getStatusCode());
        self::assertSame($this->decodeJson($first), $this->decodeJson($second));
        self::assertSame('cancelled', $this->storeBookingStatus($code));
        self::assertSame(1, $this->storeBookingCount());
    }
}
