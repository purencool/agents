<?php

namespace App\Exceptions\Handlers;

use App\PncServices\Contracts\LoggingServiceInterface;
use Illuminate\Http\Request;
use Throwable;

class Pnc500Handler
{
    public function __construct(
        protected LoggingServiceInterface $logger
    ) {}

    public function handle(Request $request, Throwable $e): void
    {
        $statusCode = method_exists($e, 'getStatusCode')
            ? $e->getStatusCode()
            : 500;

        if ($statusCode !== 500) {
            return;
        }

        $this->logger->logRequestError(
            $request->method(),
            $request->fullUrl(),
            500,
            $e->getMessage(),
            [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'request_id' => $request->header('X-Request-Id', null),
            ]
        );
    }
}
