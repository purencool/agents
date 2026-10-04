<?php

namespace Tests\Unit\PncServices\Logging;

use PHPUnit\Framework\TestCase;
use App\PncServices\Logging\LoggingService;
use App\PncServices\Contracts\LoggingServiceInterface;

class LoggingServiceTest extends TestCase
{
    public function test_it_implements_interface(): void
    {
        $this->assertInstanceOf(LoggingServiceInterface::class, new LoggingService());
    }

    public function test_log_error_does_not_throw(): void
    {
        $service = new LoggingService();
        $this->expectNotToPerformAssertions();
        $service->logError(500, 'Test error', ['test' => true]);
    }

    public function test_log_request_error_does_not_throw(): void
    {
        $service = new LoggingService();
        $this->expectNotToPerformAssertions();
        $service->logRequestError('POST', '/api/test', 500, 'Test', []);
    }
}
