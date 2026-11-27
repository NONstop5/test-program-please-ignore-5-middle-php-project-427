<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

require_once __DIR__ . '/FlightApiTestCase.php';

abstract class BookingApiTestCase extends FlightApiTestCase
{
    /**
     * @param array<mixed>|string|null $payload Raw body when a string, no body when null.
     */
    protected function bookingRequest(array|string|null $payload): ResponseInterface
    {
        $app = Application::create('test', new NullLogger());
        $app->getContainer()->set(PDO::class, $this->db);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bookings')
            ->withHeader('Content-Type', 'application/json');

        if ($payload !== null) {
            $body = is_string($payload)
                ? $payload
                : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $request->getBody()->write($body);
        }

        return $app->handle($request);
    }

    /**
     * @return array<mixed>
     */
    protected function decodeJson(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function fixtureFlightId(string $flightNumber = 'SU1002'): string
    {
        $statement = $this->db->prepare('SELECT id FROM flights WHERE flight_number = :number');
        $statement->execute(['number' => $flightNumber]);
        $id = $statement->fetchColumn();

        return $id === false ? '' : (string) $id;
    }

    /**
     * @param list<array<string, string>>|null $passengers
     * @return array<string, mixed>
     */
    public static function validPayload(string $flightId, ?array $passengers = null): array
    {
        return [
            'flightId' => $flightId,
            'contact' => self::validContact(),
            'passengers' => $passengers ?? [self::validPassenger()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function validContact(): array
    {
        return ['email' => 'ivan@example.com', 'phone' => '+7 900 000-00-00'];
    }

    /**
     * A document number of "1" must be accepted: only emptiness is validated, never a format.
     *
     * @return array<string, string>
     */
    public static function validPassenger(
        string $firstName = 'Иван',
        string $lastName = 'Петров',
        string $documentNumber = '1'
    ): array {
        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'dateOfBirth' => '1990-05-15',
            'documentNumber' => $documentNumber,
        ];
    }

    protected function storePassengerCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM passengers')->fetchColumn();
    }

    protected function storeBookingCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
    }
}
