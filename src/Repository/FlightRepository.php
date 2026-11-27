<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class FlightRepository
{
    private const SELECT_FLIGHTS = <<<'SQL'
        SELECT
            f.id,
            f.flight_number,
            f.departure_at,
            f.arrival_at,
            f.duration_minutes,
            f.price,
            f.available_seats,
            a.code AS airline_code,
            a.name AS airline_name,
            origin.code AS origin_code,
            origin.name AS origin_name,
            origin.country AS origin_country,
            destination.code AS destination_code,
            destination.name AS destination_name,
            destination.country AS destination_country
        FROM flights f
        JOIN airlines a ON a.id = f.airline_id
        JOIN cities origin ON origin.id = f.departure_city_id
        JOIN cities destination ON destination.id = f.arrival_city_id
    SQL;

    public function __construct(private readonly PDO $db)
    {
    }

    public function search(string $origin, string $destination, string $date, string $passengers): array
    {
        $sql = self::SELECT_FLIGHTS . <<<'SQL'
             WHERE origin.code = :origin AND destination.code = :destination
               AND f.departure_at >= (CAST(:start_date AS timestamp) AT TIME ZONE 'UTC')
               AND f.departure_at < ((CAST(:end_date AS timestamp) + INTERVAL '1 day') AT TIME ZONE 'UTC')
               AND f.available_seats >= CAST(:passengers AS bigint)
             ORDER BY f.departure_at, f.id
        SQL;

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'origin' => $origin,
            'destination' => $destination,
            'start_date' => $date,
            'end_date' => $date,
            'passengers' => $passengers,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string $id): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }

        $id = ltrim($id, '0');
        if (
            $id === '' || strlen($id) > 19
            || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)
        ) {
            return null;
        }

        $statement = $this->db->prepare(self::SELECT_FLIGHTS . ' WHERE f.id = :id');
        $statement->execute(['id' => $id]);
        $flight = $statement->fetch(PDO::FETCH_ASSOC);

        return $flight === false ? null : $flight;
    }
}
