<?php

namespace App\PncAgentics\Tools;

use Illuminate\Support\Facades\Http;

class McpClient
{
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
