<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Pnc;

use App\PncServices\Contracts\AgentDataInterface;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the {@see \App\Console\Commands\Pnc\AIAgent} command.
 *
 * Verifies JSON parsing, validation, delegation to AgentDataInterface,
 * output formatting, and logging.
 */
class AIAgentCommandTest extends TestCase
{
    /**
     * Mock for the agent data service.
     */
    private \Mockery\MockInterface $agentData;

    /**
     * Set up mocks and bind them to the container.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->agentData = \Mockery::mock(AgentDataInterface::class);
        $this->app->instance(AgentDataInterface::class, $this->agentData);
    }

    /**
     * Clean up Mockery after each test.
     */
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Input validation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Invalid JSON should produce an error and return FAILURE.
     */
    #[Test]
    public function handle_failsOnInvalidJson(): void
    {
        $this->artisan("pnc:agent", ["data" => "{not valid json"])
            ->expectsOutputToContain("Invalid JSON")
            ->assertFailed();
    }

    /**
     * Missing "skill" key should produce an error and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenNoSkillKey(): void
    {
        $this->artisan("pnc:agent", [
            "data" => json_encode(["prompt" => "hello"]),
        ])
            ->expectsOutputToContain("No skill specified.")
            ->assertFailed();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Happy path
    // ─────────────────────────────────────────────────────────────────────

    /**
     * On success, the command should call executeSkillRun() with the
     * decoded config and print the response.
     */
    #[Test]
    public function handle_callsExecuteSkillRunAndPrintsResponse(): void
    {
        $this->agentData
            ->shouldReceive("executeSkillRun")
            ->once()
            ->with([
                "skill" => "test.skill",
                "prompt" => "Write docs",
                "temperature" => 0.7,
            ])
            ->andReturn(["response" => "Generated documentation here."]);

        $this->artisan("pnc:agent", [
            "data" => json_encode([
                "skill" => "test.skill",
                "prompt" => "Write docs",
                "temperature" => 0.7,
            ]),
        ])
            ->expectsOutputToContain("Executing skill: test.skill")
            ->expectsOutputToContain("Generated documentation here.")
            ->expectsOutputToContain("Done in")
            ->assertSuccessful();
    }

    /**
     * The command should pass the full config array (including system
     * and temperature) to executeSkillRun().
     */
    #[Test]
    public function handle_passesFullConfigToAgentData(): void
    {
        $fullConfig = [
            "skill" => "test.skill",
            "prompt" => "Draft the Q3 report",
            "system" => "You are a senior writer.",
            "temperature" => 0.9,
        ];

        $this->agentData
            ->shouldReceive("executeSkillRun")
            ->once()
            ->with($fullConfig)
            ->andReturn(["response" => "ok"]);

        $this->artisan("pnc:agent", [
            "data" => json_encode($fullConfig),
        ])->assertSuccessful();
    }

    /**
     * The command should handle a minimal config (skill + prompt only)
     * without error.
     */
    #[Test]
    public function handle_worksWithMinimalConfig(): void
    {
        $this->agentData
            ->shouldReceive("executeSkillRun")
            ->once()
            ->with([
                "skill" => "test.skill",
                "prompt" => "Just a prompt",
            ])
            ->andReturn(["response" => "Done."]);

        $this->artisan("pnc:agent", [
            "data" => json_encode([
                "skill" => "test.skill",
                "prompt" => "Just a prompt",
            ]),
        ])
            ->expectsOutputToContain("Done.")
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Logging
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The command should log to the "daily" channel on success.
     */
    #[Test]
    public function handle_logsToDailyChannel(): void
    {
        Log::shouldReceive("channel")
            ->once()
            ->with("daily")
            ->andReturn(
                Log::shouldReceive("info")
                    ->once()
                    ->with(
                        \Mockery::on(
                            fn($msg) => str_contains(
                                $msg,
                                "SKILL RUN COMPLETED",
                            ),
                        ),
                        \Mockery::on(
                            fn($ctx) => $ctx["skill"] === "test.skill",
                        ),
                    )
                    ->mock(),
            );

        $this->agentData
            ->shouldReceive("executeSkillRun")
            ->once()
            ->andReturn(["response" => "ok"]);

        $this->artisan("pnc:agent", [
            "data" => json_encode([
                "skill" => "test.skill",
                "prompt" => "test",
            ]),
        ])->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Output formatting
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The command should print a separator line and duration on success.
     */
    #[Test]
    public function handle_printsSeparatorAndDuration(): void
    {
        $this->agentData
            ->shouldReceive("executeSkillRun")
            ->once()
            ->andReturn(["response" => "ok"]);

        $this->artisan("pnc:agent", [
            "data" => json_encode([
                "skill" => "test.skill",
                "prompt" => "test",
            ]),
        ])
            ->expectsOutputToContain(str_repeat("=", 80))
            ->expectsOutputToContain("Done in")
            ->assertSuccessful();
    }
}
