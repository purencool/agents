<?php

namespace Tests\Feature\PncAgentics\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class Test500LoggingCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_runs_successfully(): void
    {
        $this->artisan('pnc:test-500-loggin')
            ->assertSuccessful();
    }

    /** @test */
    public function it_logs_a_500_error(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, '500')
                    && isset($context['code'])
                    && $context['code'] === 500
                    && isset($context['correlation_id']);
            });

        $this->artisan('pnc:test-500-loggin')
            ->assertSuccessful();
    }

    /** @test */
    public function it_generates_a_unique_correlation_id(): void
    {
        $correlationIds = [];

        Log::shouldReceive('error')
            ->twice()
            ->withArgs(function ($message, $context) use (&$correlationIds) {
                $correlationIds[] = $context['correlation_id'];
                return true;
            });

        $this->artisan('pnc:test-500-loggin')->assertSuccessful();
        $this->artisan('pnc:test-500-loggin')->assertSuccessful();

        $this->assertCount(2, $correlationIds);
        $this->assertNotSame($correlationIds[0], $correlationIds[1]);
    }
}
