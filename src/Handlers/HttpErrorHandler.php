<?php

declare(strict_types=1);

namespace App\Handlers;

use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
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
        if ($path !== '/api' && !str_starts_with($path, '/api/')) {
            return parent::respond();
        }

        $code = match ($this->statusCode) {
            400 => 'validation_error',
            404 => 'not_found',
            405 => 'method_not_allowed',
            default => $this->statusCode >= 500 ? 'internal_error' : 'http_error',
        };
        $message = $this->exception instanceof HttpException && $this->statusCode < 500
            ? $this->exception->getMessage()
            : 'Internal server error.';

        $response = $this->responseFactory->createResponse($this->statusCode)
            ->withHeader('Content-Type', 'application/json');
        if ($this->exception instanceof HttpMethodNotAllowedException) {
            $response = $response->withHeader('Allow', implode(', ', $this->exception->getAllowedMethods()));
        }
        $response->getBody()->write(json_encode(['code' => $code, 'message' => $message], JSON_THROW_ON_ERROR));

        return $response;
    }
}
