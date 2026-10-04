<?php

namespace App\PncServices\Logging;

use App\PncServices\Contracts\LoggingServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LoggingService implements LoggingServiceInterface
{
    protected string $alertEmail;

    public function __construct()
    {
        $this->alertEmail = config('pncservices.email.alert_to');
    }

    public function logError(int $statusCode, string $message, array $context = []): void
    {
        $entry = [
            'status_code' => $statusCode,
            'message'     => $message,
            'context'     => $context,
            'timestamp'   => now()->toIso8601String(),
            'environment' => app()->environment(),
        ];

        Log::channel(config('pncservices.logging.channel', 'stack'))
            ->error("HTTP {$statusCode}", $entry);

        // Alert on 500s in production
        if ($statusCode === 500 && app()->environment() === 'production') {
            $this->sendAlert($entry);
        }
    }

    public function logRequestError(string $method, string $uri, int $statusCode, string $message, array $context = []): void
    {
        $this->logError($statusCode, $message, array_merge($context, [
            'method' => $method,
            'uri'    => $uri,
        ]));
    }

    protected function sendAlert(array $entry): void
    {
        if (empty($this->alertEmail)) {
            return;
        }

        try {
            Mail::raw(
                "500 Error Detected\n\n" .
                "Message: {$entry['message']}\n" .
                "Time: {$entry['timestamp']}\n" .
                "Env: {$entry['environment']}\n\n" .
                "Context: " . json_encode($entry['context'], JSON_PRETTY_PRINT),
                function ($m) {
                    $m->to($this->alertEmail)
                      ->subject("[ALERT] 500 Error - " . app()->environment());
                }
            );
        } catch (\Exception $e) {
            Log::error("LoggingService: Failed to send alert", ['error' => $e->getMessage()]);
        }
    }
}
