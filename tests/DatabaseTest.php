<?php

declare(strict_types=1);

namespace Tests;

use App\Migrations;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/DatabaseTestCase.php';

final class DatabaseTest extends DatabaseTestCase
{
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
}
