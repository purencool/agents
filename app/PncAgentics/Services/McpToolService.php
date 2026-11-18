<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Tools\McpClient;

/**
 * Service layer for invoking MCP (Model Context Protocol) tools.
 *
 * Wraps the low-level {@see McpClient} with timing, success detection,
 * artifact persistence, sequential execution, and health-checking.
 */
class McpToolService implements McpToolServiceInterface
{
    /**
     * @param McpClient $client The underlying MCP client used to dispatch tool calls.
     */
    public function __construct(private McpClient $client) {}

    /**
     * Call a single MCP tool and return a structured result envelope.
     *
     * Measures wall-clock elapsed time in milliseconds and determines
     * success based on the absence of a "data.error" key and a zero
     * exit code in the raw result.
     *
     * @param string      $tool   The MCP tool name (e.g. "web-fetch", "http", "search").
     * @param string      $input  The primary input / argument for the tool.
     * @param string|null $arg2   Optional secondary argument (defaults to empty string).
     * @param string|null $arg3   Optional tertiary argument (defaults to empty string).
     *
     * @return array{
     *     tool: string,
     *     input: string,
     *     elapsed_ms: int,
     *     success: bool,
     *     data: array,
     *     raw: array,
     * }
     */
    public function call(
        string $tool,
        string $input,
        ?string $arg2 = null,
        ?string $arg3 = null,
    ): array {
        $start = microtime(true);
        $result = $this->client->call($tool, $input, $arg2 ?? "", $arg3 ?? "");
        $elapsed = (int) round((microtime(true) - $start) * 1000);

        return [
            "tool" => $tool,
            "input" => $input,
            "elapsed_ms" => $elapsed,
            "success" =>
                !isset($result["data"]["error"]) &&
                ($result["exit_code"] ?? 0) === 0,
            "data" => $result["data"] ?? [],
            "raw" => $result,
        ];
    }

    /**
     * Call a tool and persist all derivable artifacts to a research directory.
     *
     * Always saves the JSON result. For the "web-fetch" tool specifically,
     * also performs a raw HTTP GET and saves the HTML body if present.
     *
     * @param string      $tool         The MCP tool name.
     * @param string      $input        The primary input / argument.
     * @param string      $researchPath Absolute or relative path to the research output directory.
     * @param string      $filename     Base filename (without extension) for saved artifacts.
     * @param string|null $driver       Optional driver argument passed through to the tool call.
     *
     * @return array The result envelope from {@see call()} augmented with
     *               "saved_json" (and optionally "saved_html") keys.
     */
    public function callWithArtifacts(
        string $tool,
        string $input,
        string $researchPath,
        string $filename = "output",
        ?string $driver = null,
    ): array {
        $result = $this->call($tool, $input, $driver);
        $saver = new FileSaver();
        $saver->setPath($researchPath);

        // Always save the JSON result.
        $result["saved_json"] = $saver->saveJson($result["data"], $filename);

        // Tool-specific additional artifacts.
        if ($tool === "web-fetch" && $result["success"]) {
            $rawResult = $this->call("http", "GET {$input}");
            if (isset($rawResult["data"]["body"])) {
                $html = is_string($rawResult["data"]["body"])
                    ? $rawResult["data"]["body"]
                    : json_encode(
                        $rawResult["data"]["body"],
                        JSON_UNESCAPED_SLASHES,
                    );
                $result["saved_html"] = $saver->saveHtml($html, $filename);
            }
        }

        return $result;
    }

    /**
     * Call multiple tools in sequence, collecting all results.
     *
     * Each call is keyed by "{tool}_{index}" in the returned array.
     *
     * @param array<int, array{0: string, 1: string}> $calls  List of [tool, input] pairs.
     * @param string|null $driver Optional driver argument shared across all calls.
     *
     * @return array<string, array> Results keyed by "{tool}_{index}".
     *
     * @example
     * $results = $service->callSequence([
     *     ['web-fetch', 'https://example.com'],
     *     ['search', 'example.com reviews'],
     * ]);
     */
    public function callSequence(array $calls, ?string $driver = null): array
    {
        $results = [];
        foreach ($calls as $i => $call) {
            [$tool, $input] = $call;
            $results["{$tool}_{$i}"] = $this->call($tool, $input, $driver);
        }

        return $results;
    }

    /**
     * Validate all available tools by issuing a test call to each.
     *
     * Intended as a pre-flight health check before starting a workflow.
     *
     * @return array<string, array{success: bool, elapsed_ms: int}>
     *         Results keyed by tool name.
     */
    public function validateAll(): array
    {
        $available = $this->availableTools();
        $results = [];

        foreach (array_keys($available) as $name) {
            $r = $this->call($name, "test");
            $results[$name] = [
                "success" => $r["success"],
                "elapsed_ms" => $r["elapsed_ms"],
            ];
        }

        return $results;
    }

    /**
     * Retrieve the list of available MCP tools.
     *
     * Issues a "help" command to the client and extracts the "commands"
     * key from the response data.
     *
     * @return array<string, mixed> Map of tool names to their metadata.
     */
    public function availableTools(): array
    {
        $result = $this->client->call("help");

        return $result["data"]["commands"] ?? [];
    }

    /**
     * Check whether any MCP tools are currently available.
     *
     * @return bool True if at least one tool is listed, false otherwise.
     */
    public function isAvailable(): bool
    {
        return !empty($this->availableTools());
    }
}
