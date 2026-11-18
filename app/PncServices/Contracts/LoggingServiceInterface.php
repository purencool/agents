<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\PncServices\Contracts;

interface LoggingServiceInterface
{
    public function logError(
        int $statusCode,
        string $message,
        array $context = [],
    ): void;
    public function logRequestError(
        string $method,
        string $uri,
        int $statusCode,
        string $message,
        array $context = [],
    ): void;
}
