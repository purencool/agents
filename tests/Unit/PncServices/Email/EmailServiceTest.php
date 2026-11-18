<?php

declare(strict_types=1);

namespace Tests\Unit\PncServices\Email;

use App\PncServices\Email\EmailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for EmailService.
 *
 * Verifies that both send() and sendTemplate() correctly delegate
 * to the Mail facade on success, and gracefully return false with
 * a logged error on failure.
 */
class EmailServiceTest extends TestCase
{
    /**
     * The service under test.
     */
    private EmailService $service;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EmailService();
    }

    /**
     * Clean up Mockery after each test.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── send() ────────────────────────────────────────────────────────

    /**
     * @testdoc Verify that send() returns true and calls Mail::raw
     *          with the correct recipient, subject, and body.
     */
    #[Test]
    public function send_returns_true_on_success(): void
    {
        Mail::shouldReceive("raw")
            ->once()
            ->withArgs(function (string $body, callable $callback): bool {
                $message = Mockery::mock();
                $message
                    ->shouldReceive("to")
                    ->once()
                    ->with("user@example.com")
                    ->andReturn($message);
                $message
                    ->shouldReceive("subject")
                    ->once()
                    ->with("Hello")
                    ->andReturn($message);

                $callback($message);

                return $body === "Test body content";
            });

        $result = $this->service->send(
            "user@example.com",
            "Hello",
            "Test body content",
        );

        $this->assertTrue($result);
    }

    /**
     * @testdoc Verify that send() returns false and logs an error
     *          when the Mail facade throws an exception.
     */
    #[Test]
    public function send_returns_false_and_logs_on_failure(): void
    {
        Mail::shouldReceive("raw")
            ->once()
            ->andThrow(new \Exception("SMTP connection refused"));

        Log::shouldReceive("error")
            ->once()
            ->withArgs(function (string $msg, array $ctx): bool {
                return str_contains($msg, "user@example.com") &&
                    $ctx["error"] === "SMTP connection refused";
            });

        $result = $this->service->send("user@example.com", "Hello", "Body");

        $this->assertFalse($result);
    }

    // ─── sendTemplate() ────────────────────────────────────────────────

    /**
     * @testdoc Verify that sendTemplate() returns true and calls Mail::send
     *          with the correct template, data, and recipient.
     */
    #[Test]
    public function sendTemplate_returns_true_on_success(): void
    {
        $data = ["name" => "Alice", "order_id" => "ORD-001"];

        Mail::shouldReceive("send")
            ->once()
            ->withArgs(function (
                string $template,
                array $data,
                callable $callback,
            ): bool {
                $message = Mockery::mock();
                $message
                    ->shouldReceive("to")
                    ->once()
                    ->with("alice@example.com")
                    ->andReturn($message);

                $callback($message);

                return $template === "emails.order-confirmation" &&
                    $data["name"] === "Alice" &&
                    $data["order_id"] === "ORD-001";
            });

        $result = $this->service->sendTemplate(
            "alice@example.com",
            "emails.order-confirmation",
            $data,
        );

        $this->assertTrue($result);
    }

    /**
     * @testdoc Verify that sendTemplate() returns false and logs an error
     *          when the Mail facade throws an exception.
     */
    #[Test]
    public function sendTemplate_returns_false_and_logs_on_failure(): void
    {
        Mail::shouldReceive("send")
            ->once()
            ->andThrow(new \Exception("Template not found"));

        Log::shouldReceive("error")
            ->once()
            ->withArgs(function (string $msg, array $ctx): bool {
                return str_contains($msg, "emails.welcome") &&
                    $ctx["error"] === "Template not found";
            });

        $result = $this->service->sendTemplate(
            "bob@example.com",
            "emails.welcome",
            [],
        );

        $this->assertFalse($result);
    }
}
