<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/BookingApiTestCase.php';

final class BookingLookupTest extends BookingApiTestCase
{
    public function testBookingIsReturnedByCodeAndLastName(): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();

        $response = $this->getBookingRequest((string) $created['code'], 'Петров');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame($created, $this->decodeJson($response));
    }

    public function testAnyPassengerLastNameMatchesTheBooking(): void
    {
        $this->initializeFlightFixtures();
        $passengers = [
            self::validPassenger('Иван', 'Петров', '1'),
            self::validPassenger('Пётр', 'Иванов', '2'),
        ];
        $created = $this->createBooking($passengers);

        $response = $this->getBookingRequest((string) $created['code'], 'Иванов');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($created, $this->decodeJson($response));
    }

    #[DataProvider('equivalentLastNames')]
    public function testLastNameComparisonIgnoresCaseAndSurroundingWhitespace(string $lastName): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();

        $response = $this->getBookingRequest((string) $created['code'], $lastName);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($created, $this->decodeJson($response));
    }

    public static function equivalentLastNames(): array
    {
        return [
            'exact match' => ['Петров'],
            'lower case' => ['петров'],
            'upper case' => ['ПЕТРОВ'],
            'mixed case' => ['пЕтРоВ'],
            'leading whitespace' => ['  Петров'],
            'trailing whitespace' => ['Петров  '],
            'surrounding whitespace' => ["\t Петров \n"],
        ];
    }

    public function testCodeIsMatchedIgnoringCase(): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();
        $code = (string) $created['code'];

        $response = $this->getBookingRequest(strtolower($code), 'Петров');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($created, $this->decodeJson($response));
    }

    #[DataProvider('mismatchedLastNames')]
    public function testWrongOrMissingLastNameReturnsNotFound(?string $lastName): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();

        $response = $this->getBookingRequest((string) $created['code'], $lastName);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $error = $this->decodeJson($response);
        self::assertSame(['code', 'message'], array_keys($error));
        self::assertSame('not_found', $error['code']);
        self::assertNotSame('', $error['message']);
    }

    public static function mismatchedLastNames(): array
    {
        return [
            'wrong last name' => ['Сидоров'],
            'missing last name' => [null],
            'empty last name' => [''],
            'blank last name' => ['   '],
        ];
    }

    public function testUnknownCodeReturnsNotFound(): void
    {
        $this->initializeFlightFixtures();
        $this->createBooking();

        $response = $this->getBookingRequest('ZZZZZZ', 'Петров');

        self::assertSame(404, $response->getStatusCode());
        $error = $this->decodeJson($response);
        self::assertSame('not_found', $error['code']);
        self::assertNotSame('', $error['message']);
    }

    public function testAllLookupFailuresShareTheSameResponseBody(): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();
        $code = (string) $created['code'];

        $unknownCode = $this->decodeJson($this->getBookingRequest('ZZZZZZ', 'Петров'));
        $wrongLastName = $this->decodeJson($this->getBookingRequest($code, 'Сидоров'));
        $missingLastName = $this->decodeJson($this->getBookingRequest($code, null));
        $emptyLastName = $this->decodeJson($this->getBookingRequest($code, ''));

        self::assertSame('not_found', $unknownCode['code']);
        self::assertSame($unknownCode, $wrongLastName);
        self::assertSame($unknownCode, $missingLastName);
        self::assertSame($unknownCode, $emptyLastName);
    }

    public function testCancelledBookingIsStillVisibleByCodeAndLastName(): void
    {
        $this->initializeFlightFixtures();
        $created = $this->createBooking();
        $code = (string) $created['code'];

        $cancelResponse = $this->cancelBookingRequest($code, ['lastName' => 'Петров']);
        self::assertSame(200, $cancelResponse->getStatusCode());

        $response = $this->getBookingRequest($code, 'Петров');

        self::assertSame(200, $response->getStatusCode());
        $booking = $this->decodeJson($response);
        self::assertSame('cancelled', $booking['status']);
        self::assertSame($this->decodeJson($cancelResponse), $booking);
    }
}
