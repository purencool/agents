<?php

namespace Tests\Feature\Pnc;

use Tests\TestCase;
use App\PncServices\Contracts\LoggingServiceInterface;

class Log500CommandTest extends TestCase
{
    public function test_command_logs_500_error(): void
    {
        $this->mock(LoggingServiceInterface::class, function ($mock) {
            $mock->shouldReceive('logRequestError')
                ->once()
                ->with('GET', '/test', 500, 'Something broke', []);
        });

        $this->artisan('pnc:log-500', [
            'message'  => 'Something broke',
            '--uri'    => '/test',
            '--method' => 'GET',
        ])->assertExitCode(0);
    }

    public function test_command_accepts_json_context(): void
    {
        $this->mock(LoggingServiceInterface::class, function ($mock) {
            $mock->shouldReceive('logRequestError')
                ->once()
                ->with('POST', '/api/data', 500, 'DB fail', ['db' => 'timeout']);
        });

        $this->artisan('pnc:log-500', [
            'message'   => 'DB fail',
            '--uri'     => '/api/data',
            '--method'  => 'POST',
            '--context' => '{"db":"timeout"}',
        ])->assertExitCode(0);
    }
}   