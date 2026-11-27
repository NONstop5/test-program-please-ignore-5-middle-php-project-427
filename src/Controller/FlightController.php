<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\FlightRepository;
use App\Service\FlightMapper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

final class FlightController
{
    private const MORE_THAN_MAX_SEATS = '2147483648';

    public function __construct(
        private readonly FlightRepository $flightRepository,
        private readonly FlightMapper $flightMapper,
    ) {
    }

    public function searchAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $origin = $this->requiredString($request, $params, 'origin');
        $destination = $this->requiredString($request, $params, 'destination');
        $date = $this->requiredString($request, $params, 'date');

        if (
            !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) ||
            !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
        ) {
            throw new HttpBadRequestException($request, 'Date must be a valid date in YYYY-MM-DD format');
        }

        $passengers = $params['passengers'] ?? '1';

        if (!is_string($passengers) || !ctype_digit($passengers) || ltrim($passengers, '0') === '') {
            throw new HttpBadRequestException($request, 'Passengers must be a positive integer');
        }

        $passengers = ltrim($passengers, '0');

        // available_seats is a PostgreSQL integer, so higher counts can never match.
        if (
            strlen($passengers) > strlen(self::MORE_THAN_MAX_SEATS) ||
            (
                strlen($passengers) === strlen(self::MORE_THAN_MAX_SEATS) &&
                strcmp($passengers, self::MORE_THAN_MAX_SEATS) > 0
            )
        ) {
            $passengers = self::MORE_THAN_MAX_SEATS;
        }

        $rows = $this->flightRepository->search($origin, $destination, $date, $passengers);
        $response->getBody()->write(json_encode(
            array_map($this->flightMapper->map(...), $rows),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));

        return $response;
    }

    public function getAction(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $row = $this->flightRepository->findById($args['id']);

        if ($row === null) {
            throw new HttpNotFoundException($request, 'Flight not found');
        }

        $response->getBody()->write(json_encode($this->flightMapper->map($row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    private function requiredString(ServerRequestInterface $request, array $params, string $name): string
    {
        $value = $params[$name] ?? null;

        if (!is_string($value) || trim($value) === '' || str_contains($value, "\0")) {
            throw new HttpBadRequestException($request, "$name is required and must be a non-empty string");
        }

        return $value;
    }
}
