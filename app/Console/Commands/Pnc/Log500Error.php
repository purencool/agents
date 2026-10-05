<?php

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;
use App\PncServices\Contracts\LoggingServiceInterface;

class Log500Error extends Command
{
    protected $signature = 'pnc:log-500
                            {message : The error message}
                            {--uri= : The request URI}
                            {--method=GET : The HTTP method}
                            {--context= : JSON-encoded additional context}';

    protected $description = 'Log a 500 error via PncServices LoggingService';

    public function handle(LoggingServiceInterface $logger): int
    {
        $message = $this->argument('message');
        $uri     = $this->option('uri') ?? '/unknown';
        $method  = $this->option('method');
        $context = json_decode($this->option('context') ?? '{}', true) ?? [];

        $logger->logRequestError($method, $uri, 500, $message, $context);

        $this->info("500 error logged: [{$method}] {$uri} — {$message}");

        return self::SUCCESS;
    }
}
