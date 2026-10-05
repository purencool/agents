<?php

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;
use App\PncServices\Contracts\LoggingServiceInterface;

class Test500Logging extends Command
{
    protected $signature = 'pnc:test-500-logging';

    protected $description = 'Test that 500 logging is wired up correctly';

    public function handle(LoggingServiceInterface $logger): int
    {
        $this->info("Testing 500 error logging...");

        $logger->logError(
            500,
            "Test: Simulated 500 error",
            ['source' => 'pnc:test-500-logging', 'test' => true]
        );

        $this->info("Logging service responded. Check your log files.");
        $this->line("   Channel: " . config('pncservices.logging.channel', 'stack'));
        $this->line("   File: " . storage_path('logs/laravel.log'));

        return self::SUCCESS;
    }
}
