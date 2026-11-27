<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;

final class BookingMapper
{
    public function __construct(private readonly FlightMapper $flightMapper)
    {
    }

    /**
     * @param array<string, mixed> $booking
     * @param list<array<string, mixed>> $passengers
     * @param array<string, mixed> $flight
     */
    public function map(array $booking, array $passengers, array $flight): array
    {
        $utc = new DateTimeZone('UTC');

        return [
            'code' => $booking['code'],
            'status' => $booking['status'],
            'flight' => $this->flightMapper->map($flight),
            'passengers' => array_map(static fn(array $passenger): array => [
                'firstName' => $passenger['first_name'],
                'lastName' => $passenger['last_name'],
                // date_of_birth is a calendar date: it must not be shifted by a timezone conversion.
                'dateOfBirth' => (new DateTimeImmutable((string) $passenger['date_of_birth'], $utc))->format('Y-m-d'),
                'documentNumber' => $passenger['document_number'],
            ], $passengers),
            'contact' => [
                'email' => $booking['contact_email'],
                'phone' => $booking['contact_phone'],
            ],
            'totalPrice' => [
                'amount' => (int) $booking['total_price'],
                'currency' => 'RUB',
            ],
            'createdAt' => (new DateTimeImmutable((string) $booking['created_at']))
                ->setTimezone($utc)
                ->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
