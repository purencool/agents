<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace Tests\Unit\PncAgentics\Services;

use App\PncAgentics\Services\McpToolService;
use App\PncAgentics\Tools\McpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see McpToolService}.
 *
 * @covers \App\PncAgentics\Services\McpToolService
 */
class McpToolServiceTest extends TestCase
{
    /**
     * Mock MCP client injected into the service under test.
     */
    private McpClient $client;

    /**
     * The system under test.
     */
    private McpToolService $service;

    /**
     * Set up mocks and instantiate the service.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(McpClient::class);
        $this->service = new McpToolService($this->client);
    }

    // ─────────────────────────────────────────────────────────────────────
    // call()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * call() should return a structured envelope with tool name, input,
     * elapsed time, success flag, data, and raw result.
     */
    #[Test]
    public function call_returnsStructuredEnvelope(): void
    {
        $this->client
            ->method("call")
            ->with("web-fetch", "https://example.com", "", "")
            ->willReturn([
                "data" => ["body" => "<html>hello</html>"],
                "exit_code" => 0,
            ]);

        $result = $this->service->call("web-fetch", "https://example.com");

        $this->assertSame("web-fetch", $result["tool"]);
        $this->assertSame("https://example.com", $result["input"]);
        $this->assertIsInt($result["elapsed_ms"]);
        $this->assertGreaterThanOrEqual(0, $result["elapsed_ms"]);
        $this->assertTrue($result["success"]);
        $this->assertSame(["body" => "<html>hello</html>"], $result["data"]);
        $this->assertSame(
            ["data" => ["body" => "<html>hello</html>"], "exit_code" => 0],
            $result["raw"],
        );
    }

    /**
     * call() should mark success as false when the result contains a
     * "data.error" key.
     */
    #[Test]
    public function call_successFalseWhenErrorPresent(): void
    {
        $this->client->method("call")->willReturn([
            "data" => ["error" => "connection refused"],
            "exit_code" => 1,
        ]);

        $result = $this->service->call("search", "test query");

        $this->assertFalse($result["success"]);
    }

    /**
     * call() should mark success as false when exit_code is non-zero,
     * even without an explicit error key.
     */
    #[Test]
    public function call_successFalseWhenExitCodeNonZero(): void
    {
        $this->client->method("call")->willReturn([
            "data" => ["output" => "partial"],
            "exit_code" => 2,
        ]);

        $result = $this->service->call("tool", "input");

        $this->assertFalse($result["success"]);
    }

    /**
     * call() should default missing "data" key to an empty array.
     */
    #[Test]
    public function call_defaultsDataToEmptyArray(): void
    {
        $this->client->method("call")->willReturn(["exit_code" => 0]);

        $result = $this->service->call("tool", "input");

        $this->assertSame([], $result["data"]);
        $this->assertTrue($result["success"]);
    }

    /**
     * call() should pass optional arg2 and arg3 through to the client.
     */
    #[Test]
    public function call_passesOptionalArguments(): void
    {
        $this->client
            ->expects($this->once())
            ->method("call")
            ->with("tool", "input", "secondary", "tertiary")
            ->willReturn(["exit_code" => 0]);

        $this->service->call("tool", "input", "secondary", "tertiary");
    }

    /**
     * call() should pass empty strings for null optional arguments.
     */
    #[Test]
    public function call_defaultsNullArgumentsToEmptyString(): void
    {
        $this->client
            ->expects($this->once())
            ->method("call")
            ->with("tool", "input", "", "")
            ->willReturn(["exit_code" => 0]);

        $this->service->call("tool", "input");
    }

    // ─────────────────────────────────────────────────────────────────────
    // callWithArtifacts()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * callWithArtifacts() should save a JSON file and include the path
     * in the "saved_json" key of the result.
     */
    #[Test]
    public function callWithArtifacts_savesJsonAndReturnsPath(): void
    {
        $tempDir = sys_get_temp_dir() . "/mcp_test_" . uniqid();
        mkdir($tempDir, 0755, true);

        $this->client->method("call")->willReturn([
            "data" => ["body" => "content here"],
            "exit_code" => 0,
        ]);

        $result = $this->service->callWithArtifacts(
            "search",
            "test query",
            $tempDir,
            "my_output",
        );

        $this->assertArrayHasKey("saved_json", $result);
        $this->assertFileExists($result["saved_json"]);

        // Clean up
        unlink($result["saved_json"]);
        rmdir($tempDir);
    }

    /**
     * callWithArtifacts() for "web-fetch" should also save HTML when
     * the tool succeeds and the HTTP body is a string.
     */
    #[Test]
    public function callWithArtifacts_savesHtmlForWebFetch(): void
    {
        $tempDir = sys_get_temp_dir() . "/mcp_test_" . uniqid();
        mkdir($tempDir, 0755, true);

        // First call: web-fetch succeeds
        // Second call: http GET returns the body
        $this->client->method("call")->willReturnOnConsecutiveCalls(
            ["data" => ["body" => "fetched"], "exit_code" => 0],
            [
                "data" => ["body" => "<html><body>Page</body></html>"],
                "exit_code" => 0,
            ],
        );

        $result = $this->service->callWithArtifacts(
            "web-fetch",
            "https://example.com",
            $tempDir,
            "page",
        );

        $this->assertArrayHasKey("saved_json", $result);
        $this->assertArrayHasKey("saved_html", $result);
        $this->assertFileExists($result["saved_html"]);

        $html = file_get_contents($result["saved_html"]);
        $this->assertStringContainsString("<html>", $html);

        // Clean up
        array_map("unlink", glob($tempDir . "/*"));
        rmdir($tempDir);
    }

    /**
     * callWithArtifacts() for "web-fetch" should NOT save HTML when the
     * tool call fails.
     */
    #[Test]
    public function callWithArtifacts_doesNotSaveHtmlOnFailure(): void
    {
        $tempDir = sys_get_temp_dir() . "/mcp_test_" . uniqid();
        mkdir($tempDir, 0755, true);

        $this->client->method("call")->willReturn([
            "data" => ["error" => "timeout"],
            "exit_code" => 1,
        ]);

        $result = $this->service->callWithArtifacts(
            "web-fetch",
            "https://unreachable.com",
            $tempDir,
            "page",
        );

        $this->assertArrayHasKey("saved_json", $result);
        $this->assertArrayNotHasKey("saved_html", $result);

        // Clean up
        array_map("unlink", glob($tempDir . "/*"));
        rmdir($tempDir);
    }

    // ─────────────────────────────────────────────────────────────────────
    // callSequence()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * callSequence() should call each tool in order and key results
     * by "{tool}_{index}".
     */
    #[Test]
    public function callSequence_callsEachToolAndKeysResults(): void
    {
        $this->client
            ->method("call")
            ->willReturnOnConsecutiveCalls(
                ["data" => ["result" => "first"], "exit_code" => 0],
                ["data" => ["result" => "second"], "exit_code" => 0],
            );

        $results = $this->service->callSequence([
            ["web-fetch", "https://a.com"],
            ["search", "query here"],
        ]);

        $this->assertCount(2, $results);
        $this->assertArrayHasKey("web-fetch_0", $results);
        $this->assertArrayHasKey("search_1", $results);
        $this->assertSame("https://a.com", $results["web-fetch_0"]["input"]);
        $this->assertSame("query here", $results["search_1"]["input"]);
    }

    /**
     * callSequence() should pass the driver argument to every call.
     */
    #[Test]
    public function callSequence_passesDriverToAllCalls(): void
    {
        $this->client
            ->expects($this->exactly(2))
            ->method("call")
            ->with($this->anything(), $this->anything(), "my-driver", "")
            ->willReturn(["exit_code" => 0]);

        $this->service->callSequence(
            [["tool_a", "input1"], ["tool_b", "input2"]],
            "my-driver",
        );
    }

    /**
     * callSequence() with an empty array should return an empty array.
     */
    #[Test]
    public function callSequence_emptyInputReturnsEmptyArray(): void
    {
        $this->client->expects($this->never())->method("call");

        $this->assertSame([], $this->service->callSequence([]));
    }

    // ─────────────────────────────────────────────────────────────────────
    // validateAll()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * validateAll() should call each available tool with "test" input
     * and return success/elapsed for each.
     */
    #[Test]
    public function validateAll_testsEachAvailableTool(): void
    {
        // availableTools() calls client->call("help")
        // Then validateAll calls client->call for each tool
        $this->client
            ->method("call")
            ->willReturnCallback(function (string $tool) {
                if ($tool === "help") {
                    return [
                        "data" => [
                            "commands" => [
                                "web-fetch" => ["desc" => "Fetch URL"],
                                "search" => ["desc" => "Search"],
                            ],
                        ],
                    ];
                }

                return ["data" => ["ok" => true], "exit_code" => 0];
            });

        $results = $this->service->validateAll();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey("web-fetch", $results);
        $this->assertArrayHasKey("search", $results);
        $this->assertTrue($results["web-fetch"]["success"]);
        $this->assertIsInt($results["web-fetch"]["elapsed_ms"]);
    }

    /**
     * validateAll() should report success=false for tools that return errors.
     */
    #[Test]
    public function validateAll_reportsFailureForBrokenTools(): void
    {
        $this->client
            ->method("call")
            ->willReturnCallback(function (string $tool) {
                if ($tool === "help") {
                    return [
                        "data" => [
                            "commands" => [
                                "broken-tool" => ["desc" => "Broken"],
                            ],
                        ],
                    ];
                }

                return ["data" => ["error" => "crash"], "exit_code" => 1];
            });

        $results = $this->service->validateAll();

        $this->assertFalse($results["broken-tool"]["success"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // availableTools()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * availableTools() should return the "commands" array from the
     * "help" call response.
     */
    #[Test]
    public function availableTools_returnsCommandsFromHelp(): void
    {
        $this->client
            ->method("call")
            ->with("help")
            ->willReturn([
                "data" => [
                    "commands" => [
                        "web-fetch" => ["description" => "Fetch a URL"],
                        "http" => ["description" => "Raw HTTP request"],
                    ],
                ],
            ]);

        $tools = $this->service->availableTools();

        $this->assertArrayHasKey("web-fetch", $tools);
        $this->assertArrayHasKey("http", $tools);
        $this->assertCount(2, $tools);
    }

    /**
     * availableTools() should return an empty array when the "help"
     * response has no "commands" key.
     */
    #[Test]
    public function availableTools_returnsEmptyArrayWhenNoCommands(): void
    {
        $this->client->method("call")->willReturn(["data" => []]);

        $this->assertSame([], $this->service->availableTools());
    }

    // ─────────────────────────────────────────────────────────────────────
    // isAvailable()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * isAvailable() should return true when at least one tool is listed.
     */
    #[Test]
    public function isAvailable_trueWhenToolsExist(): void
    {
        $this->client->method("call")->willReturn([
            "data" => ["commands" => ["tool" => ["desc" => "x"]]],
        ]);

        $this->assertTrue($this->service->isAvailable());
    }

    /**
     * isAvailable() should return false when no tools are listed.
     */
    #[Test]
    public function isAvailable_falseWhenNoTools(): void
    {
        $this->client->method("call")->willReturn(["data" => []]);

        $this->assertFalse($this->service->isAvailable());
    }
}
