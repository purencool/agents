<?php

declare(strict_types=1);

namespace App\PncServices\Logging;

use App\PncServices\Contracts\LoggingServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Structured logging service with production alerting.
 *
 * Provides error logging with HTTP status codes, request context,
 * and automatic email alerts for 500 errors in production.
 *
 * Configuration keys (config/pncservices.php):
 *  - pncservices.logging.channel   (string) Log channel name (default "stack")
 *  - pncservices.email.alert_to    (string) Recipient for production 500 alerts
 */
class LoggingService implements LoggingServiceInterface
{
    /**
     * Email address for production alert notifications.
     */
    protected string $alertEmail;

    /**
     * Read the alert email from config at construction time.
     */
    public function __construct()
    {
        $this->alertEmail = (string) config("pncservices.email.alert_to", "");
    }

    /**
     * Log an error with HTTP status code and structured context.
     *
     * Writes to the configured log channel. If the status code is 500
     * and the environment is production, also sends an email alert.
     *
     * @param int   $statusCode HTTP status code associated with the error.
     * @param string $message    Human-readable error message.
     * @param array $context    Additional structured context data.
     */
    public function logError(
        int $statusCode,
        string $message,
        array $context = [],
    ): void {
        $entry = [
            "status_code" => $statusCode,
            "message" => $message,
            "context" => $context,
            "timestamp" => now()->toIso8601String(),
            "environment" => app()->environment(),
        ];

        Log::channel(config("pncservices.logging.channel", "stack"))->error(
            "HTTP {$statusCode}",
            $entry,
        );

        if ($statusCode === 500 && app()->environment() === "production") {
            $this->sendAlert($entry);
        }
    }

    /**
     * Log a request-specific error, merging HTTP method and URI
     * into the context automatically.
     *
     * @param string $method     HTTP method (GET, POST, etc.).
     * @param string $uri        Request URI.
     * @param int    $statusCode HTTP status code.
     * @param string $message    Error message.
     * @param array  $context    Additional context data.
     */
    public function logRequestError(
        string $method,
        string $uri,
        int $statusCode,
        string $message,
        array $context = [],
    ): void {
        $this->logError(
            $statusCode,
            $message,
            array_merge($context, [
                "method" => $method,
                "uri" => $uri,
            ]),
        );
    }

    /**
     * Send an email alert for production 500 errors.
     *
     * Silently returns if no alert email is configured. Catches and
     * logs any email-sending failures to avoid cascading errors.
     *
     * @param array $entry The structured log entry to include in the alert.
     */
    protected function sendAlert(array $entry): void
    {
        if (empty($this->alertEmail)) {
            return;
        }

        try {
            Mail::raw(
                "500 Error Detected\n\n" .
                    "Message: {$entry["message"]}\n" .
                    "Time: {$entry["timestamp"]}\n" .
                    "Env: {$entry["environment"]}\n\n" .
                    "Context: " .
                    json_encode($entry["context"], JSON_PRETTY_PRINT),
                function ($m) {
                    $m->to($this->alertEmail)->subject(
                        "[ALERT] 500 Error - " . app()->environment(),
                    );
                },
            );
        } catch (\Exception $e) {
            Log::error("LoggingService: Failed to send alert", [
                "error" => $e->getMessage(),
            ]);
        }
    }
}
