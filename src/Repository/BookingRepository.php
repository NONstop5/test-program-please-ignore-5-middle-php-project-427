<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class BookingRepository
{
    private const CODE_LENGTH = 6;

    /**
     * 0, O, 1 and I are left out so the code can be dictated over the phone.
     * The alphabet stays a subset of A-Z0-9 required by the contract.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const MAX_CODE_ATTEMPTS = 5;

    /** SQLSTATE of a PostgreSQL unique constraint violation. */
    private const UNIQUE_VIOLATION = '23505';

    private const INSERT_BOOKING = <<<'SQL'
        INSERT INTO bookings (code, flight_id, status, total_price, created_at, contact_email, contact_phone)
        VALUES (:code, :flight_id, 'confirmed', :total_price, CURRENT_TIMESTAMP, :contact_email, :contact_phone)
        RETURNING id, code, status, total_price, created_at, contact_email, contact_phone
    SQL;

    private const INSERT_PASSENGER = <<<'SQL'
        INSERT INTO passengers (booking_id, first_name, last_name, date_of_birth, document_number)
        VALUES (:booking_id, :first_name, :last_name, CAST(:date_of_birth AS date), :document_number)
    SQL;

    private const SELECT_PASSENGERS = <<<'SQL'
        SELECT id, first_name, last_name, date_of_birth, document_number
        FROM passengers
        WHERE booking_id = :booking_id
        ORDER BY id
    SQL;

    private const SELECT_BOOKING = <<<'SQL'
        SELECT id, code, status, flight_id, total_price, created_at, contact_email, contact_phone
        FROM bookings
        WHERE code = :code
    SQL;

    private const SELECT_BOOKING_FOR_UPDATE = <<<'SQL'
        SELECT id, code, status, flight_id, total_price, created_at, contact_email, contact_phone
        FROM bookings
        WHERE code = :code
        FOR UPDATE
    SQL;

    private const CANCEL_BOOKING = <<<'SQL'
        UPDATE bookings
        SET status = 'cancelled'
        WHERE id = :id
        RETURNING id, code, status, flight_id, total_price, created_at, contact_email, contact_phone
    SQL;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Stores the booking together with its passengers in a single transaction.
     *
     * @param list<array{firstName: string, lastName: string, dateOfBirth: string, documentNumber: string}> $passengers
     * @return array{booking: array<string, mixed>, passengers: list<array<string, mixed>>}
     */
    public function create(string $flightId, string $email, string $phone, array $passengers, int $totalPrice): array
    {
        $this->db->beginTransaction();

        try {
            $booking = $this->insertBooking($flightId, $email, $phone, $totalPrice);
            $bookingId = (string) $booking['id'];
            $this->insertPassengers($bookingId, $passengers);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        return ['booking' => $booking, 'passengers' => $this->findPassengers($bookingId)];
    }

    /**
     * Looks the booking up by its code and verifies that at least one passenger has
     * the given surname. Code and surname comparison are both case-insensitive and
     * ignore surrounding whitespace; Cyrillic surnames require multibyte-aware folding.
     *
     * @return array{booking: array<string, mixed>, passengers: list<array<string, mixed>>}|null
     */
    public function findByCodeAndLastName(string $code, string $lastName): ?array
    {
        $booking = $this->findBookingByCode($this->normalizeCode($code), self::SELECT_BOOKING);

        if ($booking === null) {
            return null;
        }

        $passengers = $this->findPassengers((string) $booking['id']);

        if (!$this->matchesLastName($passengers, $lastName)) {
            return null;
        }

        return ['booking' => $booking, 'passengers' => $passengers];
    }

    /**
     * Cancels the booking identified by code and surname, returning the updated row.
     * Cancelling an already canceled booking is a no-op that still succeeds.
     *
     * @return array{booking: array<string, mixed>, passengers: list<array<string, mixed>>}|null
     */
    public function cancelByCodeAndLastName(string $code, string $lastName): ?array
    {
        $this->db->beginTransaction();

        try {
            // FOR UPDATE keeps two concurrent cancellations of the same booking serialized.
            $booking = $this->findBookingByCode($this->normalizeCode($code), self::SELECT_BOOKING_FOR_UPDATE);

            if ($booking === null) {
                $this->db->rollBack();

                return null;
            }

            $passengers = $this->findPassengers((string) $booking['id']);

            if (!$this->matchesLastName($passengers, $lastName)) {
                $this->db->rollBack();

                return null;
            }

            $booking = $this->updateStatus((string) $booking['id']);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        return ['booking' => $booking, 'passengers' => $passengers];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBookingByCode(string $code, string $sql): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute(['code' => $code]);
        $booking = $statement->fetch(PDO::FETCH_ASSOC);

        return $booking === false ? null : $booking;
    }

    /**
     * @return array<string, mixed>
     */
    private function updateStatus(string $bookingId): array
    {
        $statement = $this->db->prepare(self::CANCEL_BOOKING);
        $statement->execute(['id' => $bookingId]);
        $booking = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($booking)) {
            throw new RuntimeException('Booking cancel did not return the updated row');
        }

        return $booking;
    }

    private function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code), 'UTF-8');
    }

    /**
     * @param list<array<string, mixed>> $passengers
     */
    private function matchesLastName(array $passengers, string $lastName): bool
    {
        $needle = mb_strtolower(trim($lastName), 'UTF-8');

        if ($needle === '') {
            return false;
        }

        foreach ($passengers as $passenger) {
            if (mb_strtolower(trim((string) $passenger['last_name']), 'UTF-8') === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Uniqueness of the code is guaranteed by the database constraint: a pre-insert
     * check would not protect against two concurrent requests.
     *
     * @return array<string, mixed>
     */
    private function insertBooking(string $flightId, string $email, string $phone, int $totalPrice): array
    {
        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++) {
            // A constraint violation aborts the whole transaction in PostgreSQL,
            // so the insert is wrapped into a savepoint that can be rolled back alone.
            $this->db->exec('SAVEPOINT booking_code');

            try {
                $statement = $this->db->prepare(self::INSERT_BOOKING);
                $statement->execute([
                    'code' => $this->generateCode(),
                    'flight_id' => $flightId,
                    'total_price' => $totalPrice,
                    'contact_email' => $email,
                    'contact_phone' => $phone,
                ]);
            } catch (PDOException $exception) {
                if ($exception->getCode() !== self::UNIQUE_VIOLATION) {
                    throw $exception;
                }

                $this->db->exec('ROLLBACK TO SAVEPOINT booking_code');

                continue;
            }

            $booking = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($booking)) {
                throw new RuntimeException('Booking insert did not return the created row');
            }

            $this->db->exec('RELEASE SAVEPOINT booking_code');

            return $booking;
        }

        throw new RuntimeException('Unable to generate a unique booking code');
    }

    /**
     * @param list<array{firstName: string, lastName: string, dateOfBirth: string, documentNumber: string}> $passengers
     */
    private function insertPassengers(string $bookingId, array $passengers): void
    {
        $statement = $this->db->prepare(self::INSERT_PASSENGER);

        foreach ($passengers as $passenger) {
            $statement->execute([
                'booking_id' => $bookingId,
                'first_name' => $passenger['firstName'],
                'last_name' => $passenger['lastName'],
                'date_of_birth' => $passenger['dateOfBirth'],
                'document_number' => $passenger['documentNumber'],
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findPassengers(string $bookingId): array
    {
        $statement = $this->db->prepare(self::SELECT_PASSENGERS);
        $statement->execute(['booking_id' => $bookingId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function generateCode(): string
    {
        $lastIndex = strlen(self::CODE_ALPHABET) - 1;
        $code = '';

        for ($position = 0; $position < self::CODE_LENGTH; $position++) {
            $code .= self::CODE_ALPHABET[random_int(0, $lastIndex)];
        }

        return $code;
    }
}
