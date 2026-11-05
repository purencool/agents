<?php

namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Contracts\FileSaverInterface;
use App\PncAgentics\Tools\McpClient;

class McpToolService implements McpToolServiceInterface
{
    public function __construct(
        private McpClient $client,
    ) {}

    public function call(string $tool, string $input, ?string $arg2 = null, ?string $arg3 = null): array
    {
        $start = microtime(true);
        $result = $this->client->call($tool, $input, $arg2 ?? '', $arg3 ?? '');
        $elapsed = round((microtime(true) - $start) * 1000);

        return [
            'tool'       => $tool,
            'input'      => $input,
            'elapsed_ms' => $elapsed,
            'success'    => !isset($result['data']['error']) && ($result['exit_code'] ?? 0) === 0,
            'data'       => $result['data'] ?? [],
            'raw'        => $result,
        ];
    }

    /**
     * Call a tool and save all derivable artifacts to the research path.
     */
    public function callWithArtifacts(
        string $tool,
        string $input,
        string $researchPath,
        string $filename = 'output',
        ?string $driver = null,
    ): array {
        $result = $this->call($tool, $input, $driver);
        $saver = new FileSaver();
        $saver->setPath($researchPath);

        // Always save the JSON result
        $result['saved_json'] = $saver->saveJson($result['data'], $filename);

        // Tool-specific additional artifacts
        if ($tool === 'web-fetch' && $result['success']) {
            $rawResult = $this->call('http', "GET {$input}");
            if (isset($rawResult['data']['body'])) {
                $html = is_string($rawResult['data']['body'])
                    ? $rawResult['data']['body']
                    : json_encode($rawResult['data']['body'], JSON_UNESCAPED_SLASHES);
                $result['saved_html'] = $saver->saveHtml($html, $filename);
            }
        }

        return $result;
    }

    /**
     * Call multiple tools in sequence, returning all results.
     * e.g. callSequence([['web-fetch', 'https://example.com'], ['search', 'example.com reviews']])
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
     * Validate all tools (health check before starting a workflow).
     */
    public function validateAll(): array
    {
        $available = $this->availableTools();
        $results = [];

        foreach (array_keys($available) as $name) {
            $r = $this->call($name, 'test');
            $results[$name] = [
                'success'    => $r['success'],
                'elapsed_ms' => $r['elapsed_ms'],
            ];
        }

        return $results;
    }

    public function availableTools(): array
    {
        $result = $this->client->call('help');
        return $result['data']['commands'] ?? [];
    }

    public function isAvailable(): bool
    {
        return !empty($this->availableTools());
    }
}
