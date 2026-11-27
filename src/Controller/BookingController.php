<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BookingRepository;
use App\Repository\FlightRepository;
use App\Service\BookingMapper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

final class BookingController
{
    private const MAX_TEXT_LENGTH = 255;
    private const MAX_PHONE_LENGTH = 50;
    private const MAX_FLIGHT_ID_LENGTH = 20;

    /**
     * The same message is used for an unknown code, a wrong surname and a missing surname:
     * telling these cases apart would let an attacker enumerate valid six-character codes.
     */
    private const NOT_FOUND_MESSAGE = 'Booking not found';

    public function __construct(
        private readonly FlightRepository $flightRepository,
        private readonly BookingRepository $bookingRepository,
        private readonly BookingMapper $bookingMapper,
    ) {
    }

    public function createAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            throw new HttpBadRequestException($request, 'Request body must be a JSON object');
        }

        $flightId = $this->requiredString($request, $body['flightId'] ?? null, 'flightId', self::MAX_FLIGHT_ID_LENGTH);
        $contact = $this->requiredContact($request, $body['contact'] ?? null);
        $passengers = $this->requiredPassengers($request, $body['passengers'] ?? null);

        $flight = $this->flightRepository->findById($flightId);

        if ($flight === null) {
            throw new HttpBadRequestException($request, 'FlightId refers to an unknown flight');
        }

        // The client never sends the price: the server calculates it from the flight
        $totalPrice = (int) $flight['price'] * count($passengers);

        $result = $this->bookingRepository->create(
            $flightId,
            $contact['email'],
            $contact['phone'],
            $passengers,
            $totalPrice
        );

        $booking = $this->bookingMapper->map($result['booking'], $result['passengers'], $flight);
        $response->getBody()->write(json_encode($booking, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response->withStatus(201);
    }

    /**
     * @param array<string, string> $args
     */
    public function showAction(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $params = $request->getQueryParams();
        $lastName = $this->optionalLastName($params['lastName'] ?? null);

        $result = $this->bookingRepository->findByCodeAndLastName((string) $args['code'], $lastName);

        if ($result === null) {
            throw new HttpNotFoundException($request, self::NOT_FOUND_MESSAGE);
        }

        $booking = $this->mapBookingResult($result);
        $response->getBody()->write(json_encode($booking, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    /**
     * Cancellation is an action on the resource, not its removal, so the booking keeps
     * existing with a changed status and the frontend receives the new status to redraw
     * the card; an empty body would break that contract.
     *
     * @param array<string, string> $args
     */
    public function cancelAction(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $request->getParsedBody();
        $lastName = $this->optionalLastName(is_array($body) ? ($body['lastName'] ?? null) : null);

        $result = $this->bookingRepository->cancelByCodeAndLastName((string) $args['code'], $lastName);

        if ($result === null) {
            throw new HttpNotFoundException($request, self::NOT_FOUND_MESSAGE);
        }

        $booking = $this->mapBookingResult($result);
        $response->getBody()->write(json_encode($booking, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    /**
     * A missing, non-string or blank lastName must never be distinguishable from a wrong
     * one, so it is normalized to an empty string that can never match a real surname.
     */
    private function optionalLastName(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array{booking: array<string, mixed>, passengers: list<array<string, mixed>>} $result
     * @return array<string, mixed>
     */
    private function mapBookingResult(array $result): array
    {
        $flight = $this->flightRepository->findById((string) $result['booking']['flight_id']);

        // The foreign key guarantees the flight exists; this is a safety net, not a user-facing case.
        if ($flight === null) {
            throw new RuntimeException('Booking references an unknown flight');
        }

        return $this->bookingMapper->map($result['booking'], $result['passengers'], $flight);
    }

    /**
     * @return array{email: string, phone: string}
     */
    private function requiredContact(ServerRequestInterface $request, mixed $contact): array
    {
        if (!is_array($contact)) {
            throw new HttpBadRequestException($request, 'Contact is required and must be an object');
        }

        return [
            'email' => $this->requiredString($request, $contact['email'] ?? null, 'contact.email', self::MAX_TEXT_LENGTH),
            'phone' => $this->requiredString($request, $contact['phone'] ?? null, 'contact.phone', self::MAX_PHONE_LENGTH),
        ];
    }

    /**
     * Only presence and emptiness are validated, never a format: a document number "1" is valid.
     *
     * @return list<array{firstName: string, lastName: string, dateOfBirth: string, documentNumber: string}>
     */
    private function requiredPassengers(ServerRequestInterface $request, mixed $passengers): array
    {
        if (!is_array($passengers)) {
            throw new HttpBadRequestException($request, 'Passengers is required and must be a non-empty array');
        }

        if ($passengers === []) {
            throw new HttpBadRequestException($request, 'Passengers must contain at least one passenger');
        }

        $validated = [];

        foreach ($passengers as $index => $passenger) {
            $prefix = "passengers[$index]";

            if (!is_array($passenger)) {
                throw new HttpBadRequestException($request, "$prefix must be an object");
            }

            $validated[] = [
                'firstName' => $this->requiredString(
                    $request,
                    $passenger['firstName'] ?? null,
                    "$prefix.firstName",
                    self::MAX_TEXT_LENGTH
                ),
                'lastName' => $this->requiredString(
                    $request,
                    $passenger['lastName'] ?? null,
                    "$prefix.lastName",
                    self::MAX_TEXT_LENGTH
                ),
                'dateOfBirth' => $this->requiredDate($request, $passenger['dateOfBirth'] ?? null, "$prefix.dateOfBirth"),
                'documentNumber' => $this->requiredString(
                    $request,
                    $passenger['documentNumber'] ?? null,
                    "$prefix.documentNumber",
                    self::MAX_TEXT_LENGTH
                ),
            ];
        }

        return $validated;
    }

    /**
     * A value the date column would reject is answered with 400 before it reaches the database.
     */
    private function requiredDate(ServerRequestInterface $request, mixed $value, string $name): string
    {
        $date = $this->requiredString($request, $value, $name, 10);

        if (
            !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) ||
            !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
        ) {
            throw new HttpBadRequestException($request, "$name must be a valid date in YYYY-MM-DD format");
        }

        return $date;
    }

    private function requiredString(ServerRequestInterface $request, mixed $value, string $name, int $maxLength): string
    {
        if (!is_string($value) || trim($value) === '' || str_contains($value, "\0")) {
            throw new HttpBadRequestException($request, "$name is required and must be a non-empty string");
        }

        if (strlen($value) > $maxLength) {
            throw new HttpBadRequestException($request, "$name must not be longer than $maxLength characters");
        }

        return $value;
    }
}
