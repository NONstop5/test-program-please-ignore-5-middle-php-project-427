<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;

final class FlightMapper
{
    public function map(array $row): array
    {
        $utc = new DateTimeZone('UTC');

        return [
            'id' => (string) $row['id'],
            'flightNumber' => $row['flight_number'],
            'airline' => [
                'code' => $row['airline_code'],
                'name' => $row['airline_name'],
            ],
            'origin' => [
                'code' => $row['origin_code'],
                'name' => $row['origin_name'],
                'country' => $row['origin_country'],
            ],
            'destination' => [
                'code' => $row['destination_code'],
                'name' => $row['destination_name'],
                'country' => $row['destination_country'],
            ],
            'departureAt' => (new DateTimeImmutable($row['departure_at']))
                ->setTimezone($utc)
                ->format('Y-m-d\TH:i:s.u\Z'),
            'arrivalAt' => (new DateTimeImmutable($row['arrival_at']))
                ->setTimezone($utc)
                ->format('Y-m-d\TH:i:s.u\Z'),
            'durationMinutes' => (int) $row['duration_minutes'],
            'price' => [
                'amount' => (int) $row['price'],
                'currency' => 'RUB',
            ],
            'seatsAvailable' => (int) $row['available_seats'],
        ];
    }
}
