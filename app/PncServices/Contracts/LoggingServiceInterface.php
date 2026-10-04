<?php

namespace App\PncServices\Contracts;

interface LoggingServiceInterface
{
    public function logError(int $statusCode, string $message, array $context = []): void;
    public function logRequestError(string $method, string $uri, int $statusCode, string $message, array $context = []): void;
}
