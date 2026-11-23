<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Application service provider.
 *
 * Responsibilities:
 *  - Merges the PNC package config (config/pnc.php) under the "pnc" key.
 *  - Binds workflow classes that require primitive constructor arguments
 *    (which the container cannot auto-resolve).
 *  - Creates integration-team overlay directories at boot time.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Merges PNC config and binds workflow classes whose constructors
     * accept primitive types (strings) that the container cannot infer.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . "/../../config/pnc.php", "pnc");

        $this->app->singleton("pnc.skills", function () {
            $base = app_path("PncAgentics/Skills");
            $skills = [];

            if (File::isDirectory($base)) {
                foreach (File::allFiles($base) as $file) {
                    if ($file->getExtension() === "json") {
                        $data = json_decode($file->getContents(), true);

                        if (json_last_error() !== JSON_ERROR_NONE) {
                            continue;
                        }

                        $name = $data["name"] ?? null;
                        if ($name === null) {
                            continue;
                        }

                        $skills[$name] = $data;
                    }
                }
            }

            return $skills;
        });
    }

    /**
     * Bootstrap any application services.
     *
     * Creates filesystem directories for integration-team overlays.
     * These are host-mounted volumes (Docker env_file) where the
     * integration team drops custom workflow/skill JSON files that
     * override the baked-in defaults at runtime.
     */
    public function boot(): void
    {
        $this->ensureDirectory("AGENT_DATA_STORE");
        $this->ensureDirectory("OVERLAY_WORKFLOWS");
        $this->ensureDirectory("OVERLAY_OVERRIDES");
    }

    /**
     * Create a directory from an env var if it doesn't already exist.
     *
     * The env var is treated as a path relative to base_path().
     * Logs an info message when a new directory is created.
     *
     * @param string $envVar The environment variable name.
     */
    protected function ensureDirectory(string $envVar): void
    {
        $envPath = env($envVar);

        if (!$envPath) {
            return;
        }

        $resolvedPath = base_path($envPath);

        if (!is_dir($resolvedPath)) {
            mkdir($resolvedPath, 0755, true);
            Log::info("{$envVar} directory created at: {$resolvedPath}");
        }
    }
}
