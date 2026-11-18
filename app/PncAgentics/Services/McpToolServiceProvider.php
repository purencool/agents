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
use Illuminate\Support\ServiceProvider;

/**
 * Registers the MCP tool service binding in the application container.
 *
 * Binds {@see McpToolServiceInterface} to its concrete implementation
 * {@see McpToolService}, enabling dependency injection throughout the app.
 */
class McpToolServiceProvider extends ServiceProvider
{
    /**
     * Register the service binding.
     *
     * Resolves McpToolServiceInterface to McpToolService (non-shared / transient).
     * Each resolution from the container produces a new instance.
     */
    public function register(): void
    {
        $this->app->bind(McpToolServiceInterface::class, McpToolService::class);
    }
}
