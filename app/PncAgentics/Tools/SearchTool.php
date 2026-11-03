<?php

namespace App\PncAgentics\Tools;

class SearchTool implements ToolInterface
{
    public function name(): string
    {
        return 'search';
    }

    public function description(): string
    {
        return 'php server.php search "query" [driver]';
    }

    public function execute(string $input): array
    {
        return app(McpClient::class)->call('search', $input);
    }
}
