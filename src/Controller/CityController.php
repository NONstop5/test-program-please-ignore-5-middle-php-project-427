<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CityRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CityController
{
    public function __construct(private readonly CityRepository $cityRepository)
    {
    }

    public function listAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = json_encode($this->cityRepository->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($json);

        return $response;
    }
}
