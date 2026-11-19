<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics;

use App\PncAgentics\Contracts\AgentDataInterface;
use App\PncAgentics\Services\AgentDataService;
use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use App\PncAgentics\Factory\WorkflowFactory;
use App\PncAgentics\Services\AuditedSkillExecutor;
use App\PncAgentics\Services\McpToolService;
use App\PncAgentics\Services\SkillExecutor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        $this->app->bind(AgentDataInterface::class, AgentDataService::class);
        $this->app->bind(SkillExecutorInterface::class, function ($app) {
            return new AuditedSkillExecutor($app->make(SkillExecutor::class));
        });

        $this->app->bind(
            WorkflowFactoryInterface::class,
            WorkflowFactory::class,
        );

        $this->app->singleton(
            McpToolServiceInterface::class,
            McpToolService::class,
        );

        $this->app->singleton("pnc.skills", function () {
            $base = app_path("PncAgentics/Skills");
            $skills = [];

            if (File::isDirectory($base)) {
                foreach (File::allFiles($base) as $file) {
                    if ($file->getExtension() === "md") {
                        $relativePath = $file->getRelativePathname();
                        $pathWithoutExt = Str::replaceLast(
                            ".md",
                            "",
                            $relativePath,
                        );
                        $key = Str::lower(
                            str_replace(["/", "\\"], ".", $pathWithoutExt),
                        );
                        if (Str::endsWith($key, ".skills")) {
                            $key = Str::replaceLast(".skills", "", $key);
                        }
                        $skills[$key] = $file->getContents();
                    }
                }
            }

            return $skills;
        });
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
