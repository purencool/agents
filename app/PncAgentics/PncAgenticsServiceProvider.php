<?php

namespace App\PncAgentics;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\PncAgentics\Agents\Agent;
use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Services\SkillExecutor;
use App\PncAgentics\Services\AuditedSkillExecutor;
use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use App\PncAgentics\Factory\WorkflowFactory;

class PncAgenticsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(Agent::class);

        // Register the base engine execution implementation
        $this->app->bind(SkillExecutor::class, function ($app) {
            return new SkillExecutor(
                $app->make(
                    \App\PncServices\Contracts\AIServiceInterface::class,
                ),
            );
        });

        // Wrap it with the Auditing tool via the Decorator Pattern
        $this->app->bind(SkillExecutorInterface::class, function ($app) {
            return new AuditedSkillExecutor($app->make(SkillExecutor::class));
        });

        // Register the newly unified Strategy Factory
        $this->app->bind(
            WorkflowFactoryInterface::class,
            WorkflowFactory::class,
        );

        // Configuration creation mapping logic (Inside PncAgenticsServiceProvider.php)
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

                        // Convert directory separators to dot notation and enforce lower-casing
                        $key = Str::lower(
                            str_replace(["/", "\\"], ".", $pathWithoutExt),
                        );

                        // Force strip trailing filename markers out of the array mapping keys
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
     * Bootstrap services.
     */
    public function boot(): void
    {
        config(["pnc.skills" => $this->app->make("pnc.skills")]);
    }
}
