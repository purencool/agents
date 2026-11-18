<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\PncAgentics\Tools;

use Illuminate\Support\Facades\Http;

/**
 * Low-level MCP (Model Context Protocol) client.
 *
 * Dispatches tool commands via one of two transports:
 *  - "local": executes a PHP CLI script and parses its JSON stdout.
 *  - "rest":  POSTs to a remote HTTP API and parses the JSON response.
 *
 * The active transport is selected via the `mcp_client.transport` config key.
 */
class McpClient
{
    /**
     * Dispatch a command to the MCP server using the configured transport.
     *
     * @param string $command The MCP tool/command name (e.g. "web-fetch", "help").
     * @param string $query   Primary query or argument.
     * @param string $arg2    Optional secondary argument.
     * @param string $arg3    Optional tertiary argument.
     *
     * @return array{
     *     transport: string,
     *     command: string,
     *     exit_code?: int,
     *     status?: int,
     *     data: array,
     * }
     */
    public function call(
        string $command,
        string $query = "",
        string $arg2 = "",
        string $arg3 = "",
    ): array {
        $transport = config("mcp_client.transport", "local");

        return match ($transport) {
            "rest" => $this->restCall($command, $query, $arg2, $arg3),
            default => $this->localCall($command, $query, $arg2, $arg3),
        };
    }

    /**
     * Execute a command by invoking a local PHP CLI script.
     *
     * Builds a shell command from the configured server path and arguments,
     * executes it via `exec()`, and parses the JSON stdout into an array.
     *
     * Config keys used:
     *  - `mcp_client.local.server`  – path to the PHP CLI script.
     *  - `mcp_client.local.timeout` – (reserved) execution timeout in seconds.
     *
     * @param string $command The tool/command name.
     * @param string $query   Primary query.
     * @param string $arg2    Secondary argument.
     * @param string $arg3    Tertiary argument.
     *
     * @return array{
     *     transport: string,
     *     command: string,
     *     exit_code: int,
     *     data: array,
     * }
     */
    private function localCall(
        string $command,
        string $query,
        string $arg2,
        string $arg3,
    ): array {
        $server = config("mcp_client.local.server", "");
        $timeout = config("mcp_client.local.timeout", 60);

        $args = array_filter([$command, $query, $arg2, $arg3]);
        $cmd =
            "php " .
            escapeshellarg($server) .
            " " .
            implode(" ", array_map("escapeshellarg", $args));

        $output = [];
        $exitCode = 0;
        exec($cmd . " 2>&1", $output, $exitCode);
        $raw = implode("\n", $output);
        $decoded = json_decode($raw, true);

        return [
            "transport" => "local",
            "command" => $command,
            "exit_code" => $exitCode,
            "data" => $decoded ?: ["raw" => $raw],
        ];
    }

    /**
     * Execute a command by POSTing to a remote REST API.
     *
     * Config keys used:
     *  - `mcp_client.rest.base_url` – base URL of the MCP REST API.
     *  - `mcp_client.rest.api_key`  – optional bearer token for auth.
     *  - `mcp_client.rest.timeout`  – HTTP timeout in seconds.
     *
     * @param string $command The tool/command name (used as the URL path segment).
     * @param string $query   Primary query (sent as "query" in the POST body).
     * @param string $arg2    Secondary argument (sent as "arg2" if non-empty).
     * @param string $arg3    Tertiary argument (sent as "arg3" if non-empty).
     *
     * @return array{
     *     transport: string,
     *     command: string,
     *     status: int,
     *     data: array,
     * }
     */
    private function restCall(
        string $command,
        string $query,
        string $arg2,
        string $arg3,
    ): array {
        $baseUrl = rtrim(config("mcp_client.rest.base_url"), "/");
        $apiKey = config("mcp_client.rest.api_key", "");
        $timeout = config("mcp_client.rest.timeout", 60);

        $req = Http::timeout($timeout);
        if ($apiKey) {
            $req = $req->withToken($apiKey);
        }

        $response = $req->post(
            "{$baseUrl}/{$command}",
            array_filter([
                "query" => $query,
                "arg2" => $arg2,
                "arg3" => $arg3,
            ]),
        );

        return [
            "transport" => "rest",
            "command" => $command,
            "status" => $response->status(),
            "data" => $response->json() ?: ["raw" => $response->body()],
        ];
    }
}
