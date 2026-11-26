<?php

declare(strict_types=1);

namespace App\Handlers;

use Psr\Http\Message\ResponseInterface;
use Slim\Handlers\ErrorHandler;
use Throwable;

class HttpErrorHandler extends ErrorHandler
{
    /**
     * @throws Throwable
     */
    protected function respond(): ResponseInterface
    {
        $path = rawurldecode($this->request->getUri()->getPath());
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            $this->contentType = 'application/json';
        }

        return parent::respond();
    }
}
