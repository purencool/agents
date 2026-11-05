<?php

namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\McpToolServiceInterface;
use Illuminate\Support\ServiceProvider;

class McpToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            McpToolServiceInterface::class,
            McpToolService::class,
        );
    }
}
