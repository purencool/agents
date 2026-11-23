<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics;

use App\PncAgentics\Contracts\AgentDataServiceInterface;
use App\PncAgentics\Services\AgentDataService;
use App\PncAgentics\Contracts\AgentServiceInterface;
use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use App\PncAgentics\Factory\WorkflowFactory;
use App\PncAgentics\Services\AuditedSkillExecutor;
use App\PncAgentics\Services\McpToolService;
use App\PncAgentics\Services\SkillExecutor;
use App\PncAgentics\Services\AgentService;
use Illuminate\Support\ServiceProvider;
use App\PncAgentics\Services\SkillRegistry;
use App\PncAgentics\Contracts\SkillRegistryInterface;

/**
 * Service provider for the PncAgentics module.
 *
 * Registers all core bindings for the agentics subsystem:
 *  - {@see Agent} (transient)
 *  - {@see SkillExecutor} (transient, wired with AIServiceInterface)
 *  - {@see SkillExecutorInterface} → {@see AuditedSkillExecutor} (transient, wraps SkillExecutor)
 *  - {@see WorkflowFactoryInterface} → {@see WorkflowFactory} (transient)
 *  - {@see McpToolServiceInterface} → {@see McpToolService} (singleton)
 *  - "pnc.skills" (singleton, lazily scans the Skills directory for .md files)
 *
 * On boot, sets `config('pnc.skills')` to a closure that defers the
 * directory scan until the config value is first accessed.
 */
class PncAgenticsServiceProvider extends ServiceProvider
{
    /**
     * Register all service bindings.
     */
    public function register(): void
    {
        $this->app->bind(
            AgentDataServiceInterface::class,
            AgentDataService::class,
        );
        $this->app->bind(AgentServiceInterface::class, AgentService::class);
        $this->app->bind(SkillExecutorInterface::class, function ($app) {
            return new AuditedSkillExecutor($app->make(SkillExecutor::class));
        });
        $this->app->bind(SkillRegistryInterface::class, SkillRegistry::class);

        $this->app->bind(
            WorkflowFactoryInterface::class,
            WorkflowFactory::class,
        );

        $this->app->singleton(
            McpToolServiceInterface::class,
            McpToolService::class,
        );
    }

    /**
     * Boot the service provider.
     *
     * Sets `config('pnc.skills')` to a closure that lazily resolves the
     * "pnc.skills" singleton, deferring the filesystem scan until the
     * config value is actually requested.
     */
    public function boot(): void
    {
        config()->set("pnc.skills", function () {
            return app("pnc.skills");
        });
    }
}
