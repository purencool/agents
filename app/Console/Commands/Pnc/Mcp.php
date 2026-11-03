<?php

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;
use App\PncAgentics\Tools\McpClient;

class Mcp extends Command
{
    protected $signature = 'pnc:mcp {--tool= : Test a specific tool} {--input= : Test input} {--all : Test all tools} {--save= : Save output to this path (HTML for web-fetch, JSON otherwise)}';
    protected $description = 'Test MCP server connection and list available tools';

    public function handle(McpClient $client): int
    {
        $transport = config('mcp_client.transport', 'local');
        $this->info("Transport: {$transport}");

        if ($transport === 'local') {
            $this->info("Server:    " . config('mcp_client.local.server'));
        } else {
            $this->info("Base URL:  " . config('mcp_client.rest.base_url'));
        }
        $this->newLine();

        // ─── Connectivity ───
        $this->line("Testing connectivity...");
        $start = microtime(true);
        $result = $client->call('help');
        $elapsed = round((microtime(true) - $start) * 1000);

        $commands = $result['data']['commands'] ?? [];

        if (empty($commands)) {
            $this->error("❌ Connection failed: " . json_encode($result['data'] ?? $result));
            return self::FAILURE;
        }

        $this->info("✅ Connected ({$elapsed}ms) — " . count($commands) . " tools available");
        $this->newLine();

        // ─── Specific tool test ───
        if ($this->option('tool')) {
            $toolName = $this->option('tool');
            $input = $this->option('input') ?: 'test';

            if (!isset($commands[$toolName])) {
                $this->error("Unknown tool: {$toolName}");
                return self::FAILURE;
            }

            $this->line("Testing: {$toolName} (input: {$input})");
            $start = microtime(true);
            $result = $client->call($toolName, $input);
            $elapsed = round((microtime(true) - $start) * 1000);

            $ok = !isset($result['data']['error']) && ($result['exit_code'] ?? 0) === 0;
            if ($ok) {
                $this->info("✅ {$toolName} ({$elapsed}ms)");
            } else {
                $this->warn("⚠️  {$toolName} ({$elapsed}ms)");
            }
            $this->line(json_encode($result['data'], JSON_PRETTY_PRINT));

            // ─── Save output ───
            if ($this->option('save')) {
                $savePath = $this->option('save');
                $dir = dirname($savePath);
                if (!is_dir($dir)) mkdir($dir, 0755, true);

                // For web-fetch: save raw HTML + JSON
                if ($toolName === 'web-fetch' && isset($result['data']['data'])) {
                    // Re-fetch to get raw HTML (the client returns parsed JSON)
                    // Save the JSON response
                    $jsonPath = preg_replace('/\.html?$/', '.json', $savePath);
                    file_put_contents($jsonPath, json_encode($result['data'], JSON_PRETTY_PRINT));
                    $this->info("Saved JSON: {$jsonPath}");

                    // Also save the HTML by calling the raw endpoint
                    $htmlPath = $savePath;
                    if (preg_match('/\.json$/', $savePath)) {
                        $htmlPath = preg_replace('/\.json$/', '.html', $savePath);
                    }
                    // Fetch raw HTML for saving
                    $rawResult = $client->call('http', "GET {$input}");
                    if (isset($rawResult['data']['body'])) {
                        file_put_contents($htmlPath, is_string($rawResult['data']['body']) ? $rawResult['data']['body'] : json_encode($rawResult['data']['body'], JSON_PRETTY_PRINT));
                        $this->info("Saved HTML: {$htmlPath}");
                    }
                } else {
                    // For all other tools: save JSON
                    file_put_contents($savePath, json_encode($result['data'], JSON_PRETTY_PRINT));
                    $this->info("Saved: {$savePath}");
                }
            }

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        // ─── Test all ───
        if ($this->option('all')) {
            $this->line("Testing all tools...");
            $this->newLine();
            $passed = 0;
            $failed = 0;

            foreach (array_keys($commands) as $name) {
                $start = microtime(true);
                $r = $client->call($name, 'test');
                $elapsed = round((microtime(true) - $start) * 1000);

                $ok = !isset($r['data']['error']) && !isset($r['data']['Message']);
                $this->line("  " . ($ok ? "✅" : "❌") . " " . str_pad($name, 15) . " ({$elapsed}ms)");
                $ok ? $passed++ : $failed++;
            }

            $this->newLine();
            $this->info("Results: {$passed} passed, {$failed} failed, " . count($commands) . " total");
            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        // ─── Default: list all tools ───
        $this->line("Available tools:");
        $this->newLine();

        foreach ($commands as $name => $example) {
            $this->line("  " . str_pad($name, 15) . $example);
        }

        $this->newLine();
        $this->info("Use --tool=<name> --input=<value> to test a specific tool.");
        $this->info("Use --save=<path> to save output to a file.");
        $this->info("Use --all to test every tool.");

        return self::SUCCESS;
    }
}
