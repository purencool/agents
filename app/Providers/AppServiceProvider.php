<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // AGENT_DATA_STORE
        $envPath = env("AGENT_DATA_STORE");
        if ($envPath) {
            $resolvedPath = base_path($envPath);
            if (!is_dir($resolvedPath)) {
                mkdir($resolvedPath, 0755, true);
                Log::info(
                    "AGENT_DATA_STORE directory created at: {$resolvedPath}",
                );
            }
        }

        // OVERLAY_WORKFLOWS (integration team drops custom workflow JSONs here)
        $workflowOverlay = env("OVERLAY_WORKFLOWS");
        if ($workflowOverlay) {
            $workflowPath = base_path($workflowOverlay);
            if (!is_dir($workflowPath)) {
                mkdir($workflowPath, 0755, true);
                Log::info(
                    "OVERLAY_WORKFLOWS directory created at: {$workflowPath}",
                );
            }
        }

        // OVERLAY_OVERRIDES (integration team drops custom skill JSONs here)
        $skillOverlay = env("OVERLAY_OVERRIDES");
        if ($skillOverlay) {
            $skillPath = base_path($skillOverlay);
            if (!is_dir($skillPath)) {
                mkdir($skillPath, 0755, true);
                Log::info(
                    "OVERLAY_OVERRIDES directory created at: {$skillPath}",
                );
            }
        }
    }
}
