<?php

declare(strict_types=1);

namespace App\Exceptions\Handlers;

use App\PncServices\Contracts\LoggingServiceInterface;
use Illuminate\Http\Request;
use Throwable;

/**
 * Handles HTTP 500 errors by logging them through the PNC logging service.
 *
 * When an exception with a non-500 status code is encountered (e.g. a
 * Laravel HttpException with 404/422), the handler silently returns.
 * Only true server errors (status 500, or exceptions without a status
 * code method) are forwarded to the logger.
 */
class Pnc500Handler
{
    /**
     * @param LoggingServiceInterface $logger Service responsible for persisting error logs.
     */
    public function __construct(protected LoggingServiceInterface $logger) {}

    /**
     * Inspect the exception and, if it represents a 500 error, log it.
     *
     * @param Request  $request The HTTP request that triggered the exception.
     * @param Throwable $e       The exception being handled.
     *
     * @return void
     */
    public function handle(Request $request, Throwable $e): void
    {
        $statusCode = method_exists($e, "getStatusCode")
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
                "exception" => get_class($e),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
                "request_id" => $request->header("X-Request-Id", null),
            ],
        );
    }
}
