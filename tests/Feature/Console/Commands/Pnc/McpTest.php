<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Pnc;

use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Contracts\FileSaverInterface;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature tests for the Mcp console command.
 *
 * Boots the Laravel application via Tests\TestCase so that
 * the config() helper and container resolution work correctly.
 */
final class McpTest extends TestCase
{
    /** @var MockInterface&FileSaverInterface */
    private FileSaverInterface&MockInterface $saver;

    /** @var MockInterface&McpToolServiceInterface */
    private McpToolServiceInterface&MockInterface $mcp;

    /**
     * Set up mocks and config before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->saver = Mockery::mock(FileSaverInterface::class);
        $this->mcp = Mockery::mock(McpToolServiceInterface::class);

        config([
            "mcp_client.transport" => "local",
            "mcp_client.local.server" => "/usr/local/bin/mcp-server",
            "mcp_client.rest.base_url" => "http://localhost:8080",
        ]);
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
     * Bind the mocked services into the container so the command
     * receives them via type-hinted injection.
     */
    private function bindMocks(): void
    {
        $this->app->instance(McpToolServiceInterface::class, $this->mcp);
        $this->app->instance(FileSaverInterface::class, $this->saver);
    }

    /**
     * A standard map of available tools returned by the MCP service.
     */
    private function availableTools(): array
    {
        return [
            "search" => "Search the knowledge base",
            "fetch" => "Fetch a URL",
            "compute" => "Run a computation",
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Connection / availability
    // ─────────────────────────────────────────────────────────────

    /**
     * When the MCP service returns an empty tool list, the command
     * reports a connection failure and exits with FAILURE.
     */
    public function test_returns_failure_when_no_tools_available(): void
    {
        $this->bindMocks();
        $this->mcp->shouldReceive("availableTools")->once()->andReturn([]);

        $this->artisan("pnc:mcp")
            ->expectsOutputToContain("Connection failed")
            ->assertExitCode(1);
    }

    // ─────────────────────────────────────────────────────────────
    // Default listing mode (no --tool, no --all)
    // ─────────────────────────────────────────────────────────────

    /**
     * With no options the command lists all available tools and
     * exits with SUCCESS.
     */
    public function test_lists_available_tools_by_default(): void
    {
        $this->bindMocks();
        $this->mcp
            ->shouldReceive("availableTools")
            ->once()
            ->andReturn($this->availableTools());

        $this->artisan("pnc:mcp")
            ->expectsOutputToContain("Available tools")
            ->expectsOutputToContain("search")
            ->assertExitCode(0);
    }

    // ─────────────────────────────────────────────────────────────
    // --tool mode
    // ─────────────────────────────────────────────────────────────

    /**
     * Invoking a known tool via --tool calls McpToolServiceInterface::call()
     * and exits with SUCCESS when the tool reports success.
     */
    public function test_calls_tool_and_returns_success(): void
    {
        $this->bindMocks();
        $this->mcp
            ->shouldReceive("availableTools")
            ->once()
            ->andReturn($this->availableTools());
        $this->mcp
            ->shouldReceive("call")
            ->once()
            ->with("search", "test", null)
            ->andReturn([
                "success" => true,
                "elapsed_ms" => 42,
                "data" => ["hits" => 5],
            ]);

        $this->artisan("pnc:mcp", ["--tool" => "search"])
            ->expectsOutputToContain("search (42ms)")
            ->assertExitCode(0);
    }

    /**
     * When --tool references a name not in the available set, the
     * command exits with FAILURE without calling the service.
     */
    public function test_returns_failure_for_unknown_tool(): void
    {
        $this->bindMocks();
        $this->mcp
            ->shouldReceive("availableTools")
            ->once()
            ->andReturn($this->availableTools());
        $this->mcp->shouldNotReceive("call");
        $this->mcp->shouldNotReceive("callWithArtifacts");

        $this->artisan("pnc:mcp", ["--tool" => "nonexistent"])
            ->expectsOutputToContain("Unknown tool: nonexistent")
            ->assertExitCode(1);
    }

    /**
     * When --tool is combined with --save, the command delegates to
     * McpToolServiceInterface::callWithArtifacts() with the correct
     * directory, filename, and driver arguments.
     */
    public function test_calls_tool_with_artifacts_when_save_option_set(): void
    {
        $this->bindMocks();
        $this->mcp
            ->shouldReceive("availableTools")
            ->once()
            ->andReturn($this->availableTools());
        $this->mcp
            ->shouldReceive("callWithArtifacts")
            ->once()
            ->with("fetch", "test", "/tmp/out", "result", "driver-x")
            ->andReturn([
                "success" => true,
                "elapsed_ms" => 120,
                "data" => ["url" => "https://example.com"],
                "saved_json" => "/tmp/out/result.json",
                "saved_html" => "/tmp/out/result.html",
            ]);

        $this->artisan("pnc:mcp", [
            "--tool" => "fetch",
            "--save" => "/tmp/out/result.json",
            "--driver" => "driver-x",
        ])
            ->expectsOutputToContain("Saved: /tmp/out/result.json")
            ->assertExitCode(0);
    }

    /**
     * When a tool invocation reports success=false the command
     * exits with FAILURE.
     */
    public function test_returns_failure_when_tool_call_fails(): void
    {
        $this->bindMocks();
        $this->mcp
            ->shouldReceive("availableTools")
            ->once()
            ->andReturn($this->availableTools());
        $this->mcp
            ->shouldReceive("call")
            ->once()
            ->andReturn([
                "success" => false,
                "elapsed_ms" => 200,
                "data" => ["error" => "timeout"],
            ]);

        $this->artisan("pnc:mcp", ["--tool" => "search"])
            ->expectsOutputToContain("search (200ms)")
            ->assertExitCode(1);
    }

    // ─────────────────────────────────────────────────────────────
    // --all mode
    // ─────────────────────────────────────────────────────────────

    /**
     * When --all is used and every tool passes validation, the
     * command exits with SUCCESS.
     */
    public function test_validate_all_returns_success_when_all_pass(): void
    {
        $this->bindMocks();
        $this->mcp
            ->shouldReceive("availableTools")
            ->once()
            ->andReturn($this->availableTools());
        $this->mcp
            ->shouldReceive("validateAll")
            ->once()
            ->andReturn([
                "search" => ["success" => true, "elapsed_ms" => 10],
                "fetch" => ["success" => true, "elapsed_ms" => 25],
                "compute" => ["success" => true, "elapsed_ms" => 5],
            ]);

        $this->artisan("pnc:mcp", ["--all" => true])
            ->expectsOutputToContain("Results: 3 passed, 0 failed, 3 total")
            ->assertExitCode(0);
    }

    /**
     * When --all is used and at least one tool fails, the command
     * exits with FAILURE.
     */
    public function test_validate_all_returns_failure_when_any_tool_fails(): void
    {
        $this->bindMocks();
        $this->mcp
            ->shouldReceive("availableTools")
            ->once()
            ->andReturn($this->availableTools());
        $this->mcp
            ->shouldReceive("validateAll")
            ->once()
            ->andReturn([
                "search" => ["success" => true, "elapsed_ms" => 10],
                "fetch" => ["success" => false, "elapsed_ms" => 300],
                "compute" => ["success" => true, "elapsed_ms" => 5],
            ]);

        $this->artisan("pnc:mcp", ["--all" => true])
            ->expectsOutputToContain("Results: 2 passed, 1 failed, 3 total")
            ->assertExitCode(1);
    }

    // ─────────────────────────────────────────────────────────────
    // Transport display
    // ─────────────────────────────────────────────────────────────

    /**
     * When the transport is "rest" the command displays the base URL
     * instead of the local server path.
     */
    public function test_displays_rest_base_url_when_transport_is_rest(): void
    {
        $this->bindMocks();
        config(["mcp_client.transport" => "rest"]);

        $this->mcp
            ->shouldReceive("availableTools")
            ->once()
            ->andReturn($this->availableTools());

        $this->artisan("pnc:mcp")
            ->expectsOutputToContain("Base URL:http://localhost:8080")
            ->assertExitCode(0);
    }
}
