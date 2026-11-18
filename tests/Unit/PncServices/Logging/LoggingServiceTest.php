<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace Tests\Unit\PncServices\Logging;

use App\PncServices\Logging\LoggingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for LoggingService.
 */
class LoggingServiceTest extends TestCase
{
    private LoggingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            "pncservices.logging.channel" => "daily",
            "pncservices.email.alert_to" => "ops@example.com",
        ]);

        $this->service = new LoggingService();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── logError() ────────────────────────────────────────────────────────

    #[Test]
    public function logError_writes_to_configured_channel(): void
    {
        $logChannel = Mockery::mock();
        $logChannel
            ->shouldReceive("error")
            ->once()
            ->withArgs(function (string $msg, array $ctx): bool {
                return $msg === "HTTP 404" &&
                    $ctx["status_code"] === 404 &&
                    $ctx["message"] === "Not Found" &&
                    $ctx["context"] === ["route" => "/api/users"] &&
                    isset($ctx["timestamp"]) &&
                    isset($ctx["environment"]);
            });

        Log::shouldReceive("channel")
            ->once()
            ->with("daily")
            ->andReturn($logChannel);

        $this->service->logError(404, "Not Found", ["route" => "/api/users"]);

        $this->assertTrue(true);
    }

    #[Test]
    public function logError_does_not_alert_on_non_500(): void
    {
        $logChannel = Mockery::mock();
        $logChannel->shouldReceive("error")->once();

        Log::shouldReceive("channel")->once()->andReturn($logChannel);
        Mail::shouldReceive("raw")->never();

        $this->service->logError(404, "Not Found");

        $this->assertTrue(true);
    }

    #[Test]
    public function logError_does_not_alert_in_non_production(): void
    {
        $logChannel = Mockery::mock();
        $logChannel->shouldReceive("error")->once();

        Log::shouldReceive("channel")->once()->andReturn($logChannel);
        Mail::shouldReceive("raw")->never();

        $this->service->logError(500, "Server Error");

        $this->assertTrue(true);
    }

    #[Test]
    public function logError_sends_alert_on_500_in_production(): void
    {
        $this->app["env"] = "production"; // ← fix

        $logChannel = Mockery::mock();
        $logChannel->shouldReceive("error")->once();

        Log::shouldReceive("channel")->once()->andReturn($logChannel);

        Mail::shouldReceive("raw")
            ->once()
            ->withArgs(function (string $body, callable $callback): bool {
                $message = Mockery::mock();
                $message
                    ->shouldReceive("to")
                    ->once()
                    ->with("ops@example.com")
                    ->andReturn($message);
                $message->shouldReceive("subject")->once()->andReturn($message);
                $callback($message);

                return str_contains($body, "500 Error Detected") &&
                    str_contains($body, "Internal Failure");
            });

        $this->service->logError(500, "Internal Failure", [
            "exception" => "Timeout",
        ]);

        $this->assertTrue(true);
    }

    // ─── logRequestError() ─────────────────────────────────────────────────

    #[Test]
    public function logRequestError_merges_method_and_uri_into_context(): void
    {
        $logChannel = Mockery::mock();
        $logChannel
            ->shouldReceive("error")
            ->once()
            ->withArgs(function (string $msg, array $ctx): bool {
                return $ctx["context"]["method"] === "POST" &&
                    $ctx["context"]["uri"] === "/api/orders" &&
                    $ctx["context"]["user_id"] === 42;
            });

        Log::shouldReceive("channel")->once()->andReturn($logChannel);

        $this->service->logRequestError(
            "POST",
            "/api/orders",
            500,
            "Order creation failed",
            ["user_id" => 42],
        );

        $this->assertTrue(true);
    }

    // ─── sendAlert() edge cases ────────────────────────────────────────────

    #[Test]
    public function sendAlert_skips_when_email_not_configured(): void
    {
        config(["pncservices.email.alert_to" => ""]);

        $service = new LoggingService();

        $this->app["env"] = "production"; // ← fix

        $logChannel = Mockery::mock();
        $logChannel->shouldReceive("error")->once();

        Log::shouldReceive("channel")->once()->andReturn($logChannel);
        Mail::shouldReceive("raw")->never();

        $service->logError(500, "Test");

        $this->assertTrue(true);
    }

    #[Test]
    public function sendAlert_catches_email_failure(): void
    {
        $this->app["env"] = "production"; // ← fix

        $logChannel = Mockery::mock();
        $logChannel->shouldReceive("error")->once();

        Log::shouldReceive("channel")->once()->andReturn($logChannel);

        Mail::shouldReceive("raw")
            ->once()
            ->andThrow(new \Exception("SMTP down"));

        Log::shouldReceive("error")
            ->once()
            ->withArgs(function (string $msg, array $ctx): bool {
                return str_contains($msg, "Failed to send alert") &&
                    $ctx["error"] === "SMTP down";
            });

        $this->service->logError(500, "Test");

        $this->assertTrue(true);
    }
}
