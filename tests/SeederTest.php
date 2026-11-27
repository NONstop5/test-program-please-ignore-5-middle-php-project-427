<?php

declare(strict_types=1);

namespace Tests;

use App\Connection;
use App\Migrations;
use App\Seeder;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

require_once __DIR__ . '/DatabaseTestCase.php';

final class SeederTest extends DatabaseTestCase
{
    public function testAirlinesList(): void
    {
        $this->initializeDatabase();

        self::assertSame(
            ['SU', 'DP', 'S7', 'U6'],
            $this->db->query('SELECT code FROM airlines ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testScheduleCoversAllRoutesAndThirtyUtcDates(): void
    {
        $this->initializeDatabase();
        self::assertSame(2520, (int) $this->db->query('SELECT count(*) FROM flights')->fetchColumn());
        $missingRoutes = $this->db->query(<<<'SQL'
            WITH expected AS (
                SELECT origin.id AS origin_id, destination.id AS destination_id,
                       (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')::date + day.number AS date
                FROM cities origin
                CROSS JOIN cities destination
                CROSS JOIN generate_series(0, 29) AS day(number)
                WHERE origin.id <> destination.id
            )
            SELECT count(*) FROM (
                SELECT expected.origin_id, expected.destination_id, expected.date
                FROM expected
                LEFT JOIN flights f ON f.departure_city_id = expected.origin_id
                    AND f.arrival_city_id = expected.destination_id
                    AND (f.departure_at AT TIME ZONE 'UTC')::date = expected.date
                GROUP BY expected.origin_id, expected.destination_id, expected.date
                HAVING count(f.id) NOT BETWEEN 2 AND 3
            ) invalid_routes
            SQL);
        self::assertSame(0, (int) $missingRoutes->fetchColumn());
        $invalidFlights = $this->db->query(<<<'SQL'
            SELECT count(*) FROM flights f JOIN airlines a ON a.id = f.airline_id
            WHERE a.code NOT IN ('SU', 'DP', 'S7', 'U6')
               OR f.flight_number !~ ('^' || a.code || '[0-9]+$')
               OR f.departure_city_id = f.arrival_city_id
               OR f.duration_minutes NOT BETWEEN 80 AND 280
               OR f.arrival_at <> f.departure_at + f.duration_minutes * INTERVAL '1 minute'
               OR f.price NOT BETWEEN 3000 AND 8500
               OR f.available_seats NOT BETWEEN 10 AND 90
            SQL);
        self::assertSame(0, (int) $invalidFlights->fetchColumn());
        $types = $this->db->query(<<<'SQL'
            SELECT data_type FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND ((table_name = 'flights' AND column_name IN ('price', 'departure_at'))
                   OR (table_name = 'bookings' AND column_name = 'total_price'))
            ORDER BY table_name, column_name
            SQL)->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['integer', 'timestamp with time zone', 'integer'], $types);
        self::assertSame('Уральские авиалинии', $this->db->query(
            "SELECT name FROM airlines WHERE code = 'U6'"
        )->fetchColumn());
    }

    public function testRepeatedSeedingPreservesBookingsAndExtendsTheWindow(): void
    {
        $start = new DateTimeImmutable('2030-01-15 00:00:00', new DateTimeZone('UTC'));
        $this->initializeDatabase($start);
        $flight = $this->db->query('SELECT id, price FROM flights ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $booking = $this->db->prepare(<<<'SQL'
            INSERT INTO bookings (code, flight_id, total_price, contact_email, contact_phone)
            VALUES ('ABC123', :flight_id, :total_price, 'test@example.com', '123')
            SQL);
        $booking->execute(['flight_id' => $flight['id'], 'total_price' => $flight['price']]);
        $change = $this->db->prepare('UPDATE flights SET available_seats = 4, price = 9999 WHERE id = :id');
        $change->execute(['id' => $flight['id']]);

        (new Migrations())->run($this->db);
        (new Seeder())->run($this->db, $start);
        self::assertSame(2520, (int) $this->db->query('SELECT count(*) FROM flights')->fetchColumn());
        (new Seeder())->run($this->db, $start->modify('+1 day'));
        self::assertSame(2604, (int) $this->db->query('SELECT count(*) FROM flights')->fetchColumn());
        $saved = $this->db->query(<<<'SQL'
            SELECT f.available_seats, f.price, b.total_price
            FROM bookings b JOIN flights f ON f.id = b.flight_id WHERE b.code = 'ABC123'
            SQL)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(4, (int) $saved['available_seats']);
        self::assertSame(9999, (int) $saved['price']);
        self::assertSame((int) $flight['price'], (int) $saved['total_price']);

        $anotherConnection = Connection::create($this->databaseUrl);
        $anotherConnection->exec('SET search_path TO "' . $this->schema . '"');
        self::assertSame('ABC123', $anotherConnection->query('SELECT code FROM bookings')->fetchColumn());
    }

    public function testFailedSeedRollsBackAndLeavesConnectionUsable(): void
    {
        $this->initializeDatabase();
        $this->db->exec("UPDATE cities SET name = 'Before' WHERE code = 'MOW'");
        $this->db->exec(<<<'SQL'
            ALTER TABLE airlines ADD CONSTRAINT test_seed_failure CHECK (name <> 'Уральские авиалинии') NOT VALID
            SQL);
        try {
            (new Seeder())->run($this->db);
            self::fail('The seed should have failed.');
        } catch (PDOException) {
            self::assertFalse($this->db->inTransaction());
            self::assertSame('Before', $this->db->query("SELECT name FROM cities WHERE code = 'MOW'")->fetchColumn());
        }
    }
}
