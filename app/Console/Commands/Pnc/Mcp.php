<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;
use App\PncAgentics\Contracts\McpToolServiceInterface;

/**
 * CLI command for testing MCP (Model Context Protocol) server connectivity
 * and exercising individual or all registered tools.
 *
 * Supports four modes of operation:
 *  - Default: lists all available tools with their example inputs.
 *  - --tool=<name>: invokes a single tool with optional --input, --driver,
 *    and --save flags.
 *  - --all: runs validation against every registered tool and reports a
 *    pass/fail summary.
 *  - --save=<path>: (used with --tool) persists tool output artifacts to disk.
 *
 * Exit codes:
 *  - SUCCESS (0) when the operation completes without errors.
 *  - FAILURE (1) when the connection fails, an unknown tool is requested,
 *    or any tool invocation / validation fails.
 */
class Mcp extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = "pnc:mcp {--tool= : Test a specific tool} {--input= : Test input} {--all : Test all tools} {--save= : Save output to this path} {--driver= : Driver/arg2 for the tool}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Test MCP server connection and list available tools";

    /**
     * Execute the console command.
     *
     * Resolves the configured transport (local or REST), verifies connectivity
     * by fetching the list of available tools, then dispatches to the
     * appropriate sub-mode based on the provided options.
     *
     * @param McpToolServiceInterface $mcp   Service that communicates with the MCP server.
     *
     * @return int Command::SUCCESS (0) or Command::FAILURE (1).
     */
    public function handle(McpToolServiceInterface $mcp): int
    {
        $transport = config("mcp_client.transport", "local");
        $this->info("Transport: {$transport}");

        if ($transport === "local") {
            $this->info("Server: " . config("mcp_client.local.server"));
        } else {
            $this->info("Base URL:" . config("mcp_client.rest.base_url"));
        }
        $this->newLine();

        $this->line("Testing connectivity...");
        $available = $mcp->availableTools();

        if (empty($available)) {
            $this->error("Connection failed — no tools returned");
            return self::FAILURE;
        }

        $this->info("Connected — " . count($available) . " tools available");
        $this->newLine();

        if ($this->option("tool")) {
            $toolName = $this->option("tool");
            $input = $this->option("input") ?: "test";
            $driver = $this->option("driver") ?? "";

            if (!isset($available[$toolName])) {
                $this->error("Unknown tool: {$toolName}");
                return self::FAILURE;
            }

            $this->line("Testing: {$toolName} (input: {$input})");

            if ($this->option("save")) {
                $savePath = $this->option("save");
                $dir = dirname($savePath);
                $filename = pathinfo($savePath, PATHINFO_FILENAME);
                $result = $mcp->callWithArtifacts(
                    $toolName,
                    $input,
                    $dir,
                    $filename,
                    $driver ?: null,
                );
            } else {
                $result = $mcp->call($toolName, $input, $driver ?: null);
            }

            if ($result["success"]) {
                $this->info("{$toolName} ({$result["elapsed_ms"]}ms)");
            } else {
                $this->warn("{$toolName} ({$result["elapsed_ms"]}ms)");
            }
            $this->line(json_encode($result["data"], JSON_PRETTY_PRINT));

            if (isset($result["saved_json"])) {
                $this->info("Saved: {$result["saved_json"]}");
            }
            if (isset($result["saved_html"])) {
                $this->info("Saved: {$result["saved_html"]}");
            }

            return $result["success"] ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option("all")) {
            $this->line("Testing all tools...");
            $this->newLine();

            $results = $mcp->validateAll();
            $passed = 0;
            $failed = 0;

            foreach ($results as $name => $r) {
                $ok = $r["success"];
                $this->line(
                    "  " .
                        ($ok ? "" : "") .
                        " " .
                        str_pad($name, 15) .
                        " ({$r["elapsed_ms"]}ms)",
                );
                $ok ? $passed++ : $failed++;
            }

            $this->newLine();
            $this->info(
                "Results: {$passed} passed, {$failed} failed, " .
                    count($results) .
                    " total",
            );
            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->line("Available tools:");
        $this->newLine();

        foreach ($available as $name => $example) {
            $this->line("" . str_pad($name, 15) . $example);
        }

        $this->newLine();
        $this->info(
            "Use --tool=<name> --input=<value> to test a specific tool.",
        );
        $this->info("Use --driver=<name> to select a driver.");
        $this->info("Use --save=<path> to save output to a file.");
        $this->info("Use --all to test every tool.");

        return self::SUCCESS;
    }
}
