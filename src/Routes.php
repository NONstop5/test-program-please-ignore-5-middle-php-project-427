<?php

declare(strict_types=1);

namespace App;

use App\Controller\BookingController;
use App\Controller\CityController;
use App\Controller\FlightController;
use App\Controller\IndexController;
use DI\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

class Routes
{
    /**
     * @param App<Container> $app
     */
    public static function init(App $app): void
    {
        $app->group('/api', function (RouteCollectorProxy $group) {
            $group->get('/health', [IndexController::class, 'healthAction'])->setName('health');
            $group->get('/cities', [CityController::class, 'listAction'])->setName('cities');
            $group->get('/flights', [FlightController::class, 'searchAction'])->setName('flights.search');
            $group->get('/flights/{id}', [FlightController::class, 'getAction'])->setName('flights.show');
            $group->post('/bookings', [BookingController::class, 'createAction'])->setName('bookings.create');
            $group->get('/bookings/{code}', [BookingController::class, 'showAction'])->setName('bookings.show');
            $group->post('/bookings/{code}/cancel', [BookingController::class, 'cancelAction'])->setName('bookings.cancel');
        })->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
            $response = $handler->handle($request);

            return $response->hasHeader('Content-Type')
                ? $response
                : $response->withHeader('Content-Type', 'application/json');
        });
    }
}
