<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class IndexController
{
    /**
     * @throws Throwable
     */
    public function healthAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {


        $data = ['status' => 'ok'];

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response;
    }
}
