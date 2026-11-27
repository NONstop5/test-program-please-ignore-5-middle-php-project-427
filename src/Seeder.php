<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class Seeder
{
    public function run(PDO $conn, ?DateTimeImmutable $today = null): void
    {
        $today = ($today ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));

        $conn->beginTransaction();
        try {
            $conn->exec('SELECT pg_advisory_xact_lock(427002)');
            $conn->exec(<<<'SQL'
                INSERT INTO cities (code, name, country) VALUES
                    ('MOW', 'Москва', 'Россия'),
                    ('LED', 'Санкт-Петербург', 'Россия'),
                    ('AER', 'Сочи', 'Россия'),
                    ('KZN', 'Казань', 'Россия'),
                    ('SVX', 'Екатеринбург', 'Россия'),
                    ('OVB', 'Новосибирск', 'Россия'),
                    ('KGD', 'Калининград', 'Россия')
                ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, country = EXCLUDED.country
                SQL);
            $conn->exec(<<<'SQL'
                INSERT INTO airlines (code, name) VALUES
                    ('SU', 'Аэрофлот'),
                    ('DP', 'Победа'),
                    ('S7', 'S7 Airlines'),
                    ('U6', 'Уральские авиалинии')
                ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name
                SQL);

            $statement = $conn->prepare(<<<'SQL'
                WITH city_order (code, position) AS (
                    VALUES ('MOW', 1), ('LED', 2), ('AER', 3), ('KZN', 4),
                           ('SVX', 5), ('OVB', 6), ('KGD', 7)
                ), schedule AS (
                    SELECT origin.id AS origin_id, destination.id AS destination_id,
                           airline.id AS airline_id, airline.code AS airline_code,
                           origin_order.position AS origin_position,
                           destination_order.position AS destination_position,
                           slot.number AS slot,
                           ((CAST(:start_date AS DATE) + day.number)::timestamp
                               + (slot.number * 8 * INTERVAL '1 hour')) AT TIME ZONE 'UTC' AS departure_at
                    FROM city_order origin_order
                    JOIN cities origin ON origin.code = origin_order.code
                    CROSS JOIN city_order destination_order
                    JOIN cities destination ON destination.code = destination_order.code
                    CROSS JOIN generate_series(0, 29) AS day(number)
                    CROSS JOIN generate_series(1, 2) AS slot(number)
                    JOIN airlines airline ON airline.code = (ARRAY['SU', 'DP', 'S7', 'U6'])[
                        ((origin_order.position + destination_order.position + slot.number) % 4) + 1
                    ]
                    WHERE origin.id <> destination.id
                ), flight_data AS (
                    SELECT *, 80 + ((origin_position * 23 + destination_position * 13 + slot * 17) % 201)
                        AS duration
                    FROM schedule
                )
                INSERT INTO flights (
                    airline_id, flight_number, departure_city_id, arrival_city_id,
                    departure_at, arrival_at, duration_minutes, price, available_seats
                )
                SELECT airline_id,
                       airline_code || lpad((origin_position * 100 + destination_position * 10 + slot)::text, 4, '0'),
                       origin_id, destination_id, departure_at, departure_at + duration * INTERVAL '1 minute',
                       duration, 3000 + ((origin_position * 509 + destination_position * 211 + slot * 407) % 5501),
                       10 + ((origin_position * 19 + destination_position * 11 + slot * 7) % 81)
                FROM flight_data
                ON CONFLICT (departure_city_id, arrival_city_id, departure_at) DO NOTHING
                SQL);
            $statement->execute(['start_date' => $today->format('Y-m-d')]);
            $conn->commit();
        } catch (Throwable $exception) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            throw $exception;
        }
    }
}
