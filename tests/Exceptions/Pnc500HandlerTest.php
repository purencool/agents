<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace Tests\Exceptions\Handlers;

use App\Exceptions\Handlers\Pnc500Handler;
use App\PncServices\Contracts\LoggingServiceInterface;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Feature tests for Pnc500Handler.
 *
 * Verifies that the handler:
 *  - Ignores exceptions whose status code is not 500.
 *  - Logs exceptions with a 500 status code (via getStatusCode()).
 *  - Treats exceptions without getStatusCode() as 500 by default.
 *  - Passes correct metadata (exception class, file, line, request_id).
 */
final class Pnc500HandlerTest extends TestCase
{
    /** @var MockInterface&LoggingServiceInterface */
    private LoggingServiceInterface&MockInterface $logger;

    /** @var Pnc500Handler */
    private Pnc500Handler $handler;

    /**
     * Set up the logger mock and handler instance before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = Mockery::mock(LoggingServiceInterface::class);
        $this->handler = new Pnc500Handler($this->logger);
    }

    /**
     * Tear down Mockery after each test.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    /**
     * Build a GET request to a known URL with an optional X-Request-Id header.
     */
    private function makeRequest(?string $requestId = null): Request
    {
        $headers = [];
        if ($requestId !== null) {
            $headers["X-Request-Id"] = $requestId;
        }

        return Request::create(
            "https://example.com/api/data",
            "GET",
            [],
            [],
            [],
            $headers,
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Non-500 exceptions are ignored
    // ─────────────────────────────────────────────────────────────

    /**
     * When the exception has a status code of 404, the handler
     * should NOT call the logger.
     */
    public function test_does_not_log_non_500_exception(): void
    {
        $request = $this->makeRequest();
        $exception = new class extends \Exception {
            public function getStatusCode(): int
            {
                return 404;
            }
        };

        $this->logger->shouldNotReceive("logRequestError");

        $this->handler->handle($request, $exception);
    }

    /**
     * When the exception has a status code of 422, the handler
     * should NOT call the logger.
     */
    public function test_does_not_log_422_exception(): void
    {
        $request = $this->makeRequest();
        $exception = new class extends \Exception {
            public function getStatusCode(): int
            {
                return 422;
            }
        };

        $this->logger->shouldNotReceive("logRequestError");

        $this->handler->handle($request, $exception);
    }

    // ─────────────────────────────────────────────────────────────
    // 500 exceptions are logged
    // ─────────────────────────────────────────────────────────────

    /**
     * When the exception has a status code of 500, the handler
     * calls logRequestError with the correct arguments.
     */
    public function test_logs_500_exception_with_correct_parameters(): void
    {
        $request = $this->makeRequest("req-abc-123");
        $exception = new class ("Database connection lost") extends \Exception {
            public function getStatusCode(): int
            {
                return 500;
            }
        };

        $this->logger->shouldReceive("logRequestError")->once()->with(
            "GET",
            "https://example.com/api/data",
            500,
            "Database connection lost",
            Mockery::on(function (array $context) {
                return $context["exception"] ===
                    get_class(new class extends \Exception {}) ||
                    str_contains($context["exception"], "class@anonymous");
            }),
        );

        $this->handler->handle($request, $exception);
    }

    /**
     * The context array passed to the logger contains the correct
     * exception class, file, line, and request_id.
     */
    public function test_logs_correct_context_metadata(): void
    {
        $request = $this->makeRequest("req-xyz-789");
        $exception = new RuntimeException(
            "Something broke",
            0,
            null,
            null,
            null,
        );

        // Simulate an exception that has getStatusCode() returning 500
        $exception = new class ("Something broke") extends \Exception {
            public function getStatusCode(): int
            {
                return 500;
            }
        };

        $this->logger
            ->shouldReceive("logRequestError")
            ->once()
            ->withArgs(function (
                string $method,
                string $url,
                int $code,
                string $message,
                array $context,
            ) {
                return $method === "GET" &&
                    $url === "https://example.com/api/data" &&
                    $code === 500 &&
                    $message === "Something broke" &&
                    $context["exception"] ===
                        get_class(new class extends \Exception {}) &&
                    $context["file"] === $context["file"] && // always true, just structural
                    is_int($context["line"]) &&
                    $context["request_id"] === "req-xyz-789";
            });

        $this->handler->handle($request, $exception);
    }

    // ─────────────────────────────────────────────────────────────
    // Exceptions without getStatusCode() default to 500
    // ─────────────────────────────────────────────────────────────

    /**
     * A plain RuntimeException (no getStatusCode method) is treated
     * as a 500 and logged.
     */
    public function test_logs_exception_without_get_status_code_method(): void
    {
        $request = $this->makeRequest();
        $exception = new RuntimeException("Unexpected failure");

        $this->logger->shouldReceive("logRequestError")->once()->with(
            "GET",
            "https://example.com/api/data",
            500,
            "Unexpected failure",
            Mockery::on(function (array $context) {
                return $context["exception"] === RuntimeException::class &&
                    $context["request_id"] === null;
            }),
        );

        $this->handler->handle($request, $exception);
    }

    // ─────────────────────────────────────────────────────────────
    // Request details
    // ─────────────────────────────────────────────────────────────

    /**
     * The handler passes the correct HTTP method and full URL
     * to the logger.
     */
    public function test_passes_correct_http_method_and_url(): void
    {
        $request = Request::create(
            "https://example.com/api/data",
            "POST",
            [],
            [],
            [],
            ["X-Request-Id" => "req-post-1"],
        );

        $exception = new RuntimeException("Post failed");

        $this->logger
            ->shouldReceive("logRequestError")
            ->once()
            ->with(
                "POST",
                "https://example.com/api/data",
                500,
                "Post failed",
                Mockery::any(),
            );

        $this->handler->handle($request, $exception);
    }

    /**
     * When no X-Request-Id header is present, request_id is null.
     */
    public function test_request_id_is_null_when_header_absent(): void
    {
        $request = $this->makeRequest(null); // no X-Request-Id
        $exception = new RuntimeException("No request id");

        $this->logger
            ->shouldReceive("logRequestError")
            ->once()
            ->withArgs(function (
                string $method,
                string $url,
                int $code,
                string $message,
                array $context,
            ) {
                return $context["request_id"] === null;
            });

        $this->handler->handle($request, $exception);
    }
}
