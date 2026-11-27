<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\Connection;
use App\Migrations;
use App\Seeder;
use DateTimeImmutable;
use DateTimeZone;
use Dotenv\Dotenv;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DatabaseTest extends TestCase
{
    private PDO $db;
    private string $databaseUrl;
    private string $schema;

    protected function setUp(): void
    {
        Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
        $url = getenv('TEST_DATABASE_URL') ?: ($_ENV['TEST_DATABASE_URL'] ?? $_SERVER['TEST_DATABASE_URL'] ?? null);
        $url ??= getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null);
        if (!is_string($url) || $url === '') {
            self::fail('Set TEST_DATABASE_URL or DATABASE_URL to run PostgreSQL integration tests.');
        }

        $this->databaseUrl = $url;
        $this->db = Connection::create($url);
        $schema = 'test_' . bin2hex(random_bytes(8));
        $this->db->exec('CREATE SCHEMA "' . $schema . '"');
        $this->schema = $schema;
        $this->db->exec('SET search_path TO "' . $this->schema . '"');
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->schema)) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->db->exec('DROP SCHEMA "' . $this->schema . '" CASCADE');
        }
    }

    public function testCitiesList(): void
    {
        $this->initializeDatabase();
        $app = Application::create('test', new NullLogger());
        $app->getContainer()->set(PDO::class, $this->db);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/cities');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame([
            ['code' => 'MOW', 'name' => 'Москва', 'country' => 'Россия'],
            ['code' => 'LED', 'name' => 'Санкт-Петербург', 'country' => 'Россия'],
            ['code' => 'AER', 'name' => 'Сочи', 'country' => 'Россия'],
            ['code' => 'KZN', 'name' => 'Казань', 'country' => 'Россия'],
            ['code' => 'SVX', 'name' => 'Екатеринбург', 'country' => 'Россия'],
            ['code' => 'OVB', 'name' => 'Новосибирск', 'country' => 'Россия'],
            ['code' => 'KGD', 'name' => 'Калининград', 'country' => 'Россия'],
        ], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

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

    public function testMigrationRejectsFractionalLegacyPricesWithoutDataLoss(): void
    {
        $sql = file_get_contents(dirname(__DIR__) . '/database.sql');
        self::assertIsString($sql);
        $this->db->exec($sql);
        $this->db->exec(<<<'SQL'
            INSERT INTO flights (airline_id, flight_number, departure_city_id, arrival_city_id,
                                 departure_at, arrival_at, duration_minutes, price, available_seats)
            SELECT a.id, 'SU1234', origin.id, destination.id, '2030-01-01 08:00+00',
                   '2030-01-01 10:00+00', 120, 3000.50, 10
            FROM airlines a, cities origin, cities destination
            WHERE a.code = 'SU' AND origin.code = 'MOW' AND destination.code = 'LED'
            SQL);

        try {
            (new Migrations())->run($this->db);
            self::fail('Fractional prices must not be silently rounded.');
        } catch (PDOException) {
            self::assertFalse($this->db->inTransaction());
            self::assertSame('3000.50', $this->db->query('SELECT price FROM flights')->fetchColumn());
            self::assertNull($this->db->query("SELECT to_regclass('migrations')")->fetchColumn());
        }
    }

    #[DataProvider('invalidFlights')]
    public function testFlightConstraintsRejectInvalidData(string $update): void
    {
        $this->initializeDatabase();
        try {
            $this->db->exec($update);
            self::fail('The invalid flight should have been rejected.');
        } catch (PDOException) {
            self::assertSame(2520, (int) $this->db->query('SELECT count(*) FROM flights')->fetchColumn());
        }
    }

    public static function invalidFlights(): array
    {
        return [
            'same city' => ['UPDATE flights SET arrival_city_id = departure_city_id WHERE id = 1'],
            'missing airline' => ['UPDATE flights SET airline_id = -1 WHERE id = 1'],
            'negative price' => ['UPDATE flights SET price = -1 WHERE id = 1'],
            'negative seats' => ['UPDATE flights SET available_seats = -1 WHERE id = 1'],
        ];
    }

    private function initializeDatabase(?DateTimeImmutable $today = null): void
    {
        $migrations = new Migrations();
        $migrations->run($this->db);
        (new Seeder())->run($this->db, $today);
    }
}
