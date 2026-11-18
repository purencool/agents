<?php

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Tools;

use App\PncAgentics\Tools\McpClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see McpClient}.
 *
 * Tests cover both transport modes:
 *  - "local": uses a real PHP script fixture that echoes JSON.
 *  - "rest":  uses Laravel's Http::fake() to stub HTTP responses.
 *
 * @covers \App\PncAgentics\Tools\McpClient
 */
class McpClientTest extends TestCase
{
    /**
     * The system under test.
     */
    private McpClient $client;

    /**
     * Path to a temporary PHP script used as the "local server" fixture.
     */
    private string $fixtureScript;

    /**
     * Set up the test environment.
     *
     * Creates a temporary PHP script that echoes a JSON response,
     * and configures the app for "local" transport by default.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new McpClient();

        // Create a fixture PHP script that echoes JSON.
        $this->fixtureScript =
            sys_get_temp_dir() . "/mcp_fixture_" . uniqid() . ".php";
        file_put_contents(
            $this->fixtureScript,
            <<<'PHP'
            <?php
            $command = $argv[1] ?? '';
            $query = $argv[2] ?? '';

            if ($command === 'fail') {
                fwrite(STDERR, "Something went wrong");
                exit(1);
            }

            if ($command === 'no-json') {
                echo "plain text output, not json";
                exit(0);
            }

            echo json_encode([
                'echo' => $command,
                'query' => $query,
                'ok' => true,
            ]);
            PHP
            ,
        );

        // Default config for local transport.
        config([
            "mcp_client.transport" => "local",
            "mcp_client.local.server" => $this->fixtureScript,
            "mcp_client.local.timeout" => 30,
        ]);
    }

    /**
     * Clean up the temporary fixture script.
     */
    protected function tearDown(): void
    {
        if (file_exists($this->fixtureScript)) {
            unlink($this->fixtureScript);
        }
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // call() – transport dispatch
    // ─────────────────────────────────────────────────────────────────────

    /**
     * call() should dispatch to localCall when transport is "local"
     * (or any non-"rest" value).
     */
    #[Test]
    public function call_dispatchesToLocalTransportByDefault(): void
    {
        $result = $this->client->call("test", "hello");

        $this->assertSame("local", $result["transport"]);
        $this->assertSame("test", $result["command"]);
    }

    /**
     * call() should dispatch to restCall when transport is "rest".
     */
    #[Test]
    public function call_dispatchesToRestTransportWhenConfigured(): void
    {
        config([
            "mcp_client.transport" => "rest",
            "mcp_client.rest.base_url" => "https://mcp.example.com",
            "mcp_client.rest.api_key" => "",
            "mcp_client.rest.timeout" => 30,
        ]);

        Http::fake([
            "mcp.example.com/*" => Http::response(["ok" => true], 200),
        ]);

        $result = $this->client->call("search", "query");

        $this->assertSame("rest", $result["transport"]);
        $this->assertSame(200, $result["status"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // localCall()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * localCall() should parse JSON output from the PHP script and
     * return it in the "data" key with exit_code 0.
     */
    #[Test]
    public function localCall_parsesJsonOutputSuccessfully(): void
    {
        $result = $this->client->call("my-tool", "some-query");

        $this->assertSame(0, $result["exit_code"]);
        $this->assertArrayHasKey("data", $result);
        $this->assertTrue($result["data"]["ok"]);
        $this->assertSame("my-tool", $result["data"]["echo"]);
        $this->assertSame("some-query", $result["data"]["query"]);
    }

    /**
     * localCall() should return a non-zero exit_code when the script
     * fails, and wrap the raw output in data.raw.
     */
    #[Test]
    public function localCall_returnsNonZeroExitCodeOnFailure(): void
    {
        $result = $this->client->call("fail");

        $this->assertSame(1, $result["exit_code"]);
        $this->assertArrayHasKey("raw", $result["data"]);
        $this->assertStringContainsString(
            "Something went wrong",
            $result["data"]["raw"],
        );
    }

    /**
     * localCall() should fall back to data.raw when the output is
     * not valid JSON.
     */
    #[Test]
    public function localCall_wrapsNonJsonOutputInRaw(): void
    {
        $result = $this->client->call("no-json");

        $this->assertSame(0, $result["exit_code"]);
        $this->assertArrayHasKey("raw", $result["data"]);
        $this->assertStringContainsString(
            "plain text output",
            $result["data"]["raw"],
        );
    }

    /**
     * localCall() should pass all non-empty arguments to the script
     * and filter out empty strings.
     */
    #[Test]
    public function localCall_passesNonEmptyArgumentsOnly(): void
    {
        $result = $this->client->call("my-tool", "query", "", "tertiary");

        $this->assertSame(0, $result["exit_code"]);
        $this->assertSame("my-tool", $result["data"]["echo"]);
        $this->assertSame("query", $result["data"]["query"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // restCall()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * restCall() should POST to {base_url}/{command} and return the
     * JSON response body in "data".
     */
    #[Test]
    public function restCall_postsToCorrectUrlAndReturnsData(): void
    {
        config([
            "mcp_client.transport" => "rest",
            "mcp_client.rest.base_url" => "https://mcp.example.com",
            "mcp_client.rest.api_key" => "",
            "mcp_client.rest.timeout" => 30,
        ]);

        Http::fake([
            "mcp.example.com/web-fetch" => Http::response(
                ["body" => "<html>hi</html>"],
                200,
            ),
        ]);

        $result = $this->client->call("web-fetch", "https://target.com");

        $this->assertSame("rest", $result["transport"]);
        $this->assertSame(200, $result["status"]);
        $this->assertSame(["body" => "<html>hi</html>"], $result["data"]);

        Http::assertSent(
            fn($request) => $request->url() ===
                "https://mcp.example.com/web-fetch" &&
                $request["query"] === "https://target.com",
        );
    }

    /**
     * restCall() should include the API key as a Bearer token when configured.
     */
    #[Test]
    public function restCall_sendsBearerTokenWhenApiKeyConfigured(): void
    {
        config([
            "mcp_client.transport" => "rest",
            "mcp_client.rest.base_url" => "https://mcp.example.com",
            "mcp_client.rest.api_key" => "secret-token-123",
            "mcp_client.rest.timeout" => 30,
        ]);

        Http::fake([
            "mcp.example.com/*" => Http::response(["ok" => true], 200),
        ]);

        $this->client->call("search", "test");

        Http::assertSent(
            fn($request) => $request->hasHeader("Authorization") &&
                $request->header("Authorization")[0] ===
                    "Bearer secret-token-123",
        );
    }

    /**
     * restCall() should send only non-empty arguments in the POST body.
     */
    #[Test]
    public function restCall_filtersEmptyArgumentsFromPayload(): void
    {
        config([
            "mcp_client.transport" => "rest",
            "mcp_client.rest.base_url" => "https://mcp.example.com",
            "mcp_client.rest.api_key" => "",
            "mcp_client.rest.timeout" => 30,
        ]);

        Http::fake([
            "mcp.example.com/*" => Http::response(["ok" => true], 200),
        ]);

        $this->client->call("tool", "only-query", "", "");

        Http::assertSent(
            fn($request) => $request["query"] === "only-query" &&
                !isset($request["arg2"]) &&
                !isset($request["arg3"]),
        );
    }

    /**
     * restCall() should include arg2 and arg3 in the payload when provided.
     */
    #[Test]
    public function restCall_includesAllArgumentsWhenProvided(): void
    {
        config([
            "mcp_client.transport" => "rest",
            "mcp_client.rest.base_url" => "https://mcp.example.com",
            "mcp_client.rest.api_key" => "",
            "mcp_client.rest.timeout" => 30,
        ]);

        Http::fake([
            "mcp.example.com/*" => Http::response(["ok" => true], 200),
        ]);

        $this->client->call("tool", "q", "secondary", "tertiary");

        Http::assertSent(
            fn($request) => $request["query"] === "q" &&
                $request["arg2"] === "secondary" &&
                $request["arg3"] === "tertiary",
        );
    }

    /**
     * restCall() should wrap non-JSON response bodies in data.raw.
     */
    #[Test]
    public function restCall_wrapsNonJsonBodyInRaw(): void
    {
        config([
            "mcp_client.transport" => "rest",
            "mcp_client.rest.base_url" => "https://mcp.example.com",
            "mcp_client.rest.api_key" => "",
            "mcp_client.rest.timeout" => 30,
        ]);

        Http::fake([
            "mcp.example.com/*" => Http::response("plain text error", 500),
        ]);

        $result = $this->client->call("tool", "input");

        $this->assertSame(500, $result["status"]);
        $this->assertArrayHasKey("raw", $result["data"]);
        $this->assertSame("plain text error", $result["data"]["raw"]);
    }

    /**
     * restCall() should strip trailing slashes from the base URL.
     */
    #[Test]
    public function restCall_stripsTrailingSlashFromBaseUrl(): void
    {
        config([
            "mcp_client.transport" => "rest",
            "mcp_client.rest.base_url" => "https://mcp.example.com/",
            "mcp_client.rest.api_key" => "",
            "mcp_client.rest.timeout" => 30,
        ]);

        Http::fake([
            "mcp.example.com/*" => Http::response(["ok" => true], 200),
        ]);

        $this->client->call("tool", "input");

        Http::assertSent(
            fn($request) => $request->url() === "https://mcp.example.com/tool",
        );
    }
}
