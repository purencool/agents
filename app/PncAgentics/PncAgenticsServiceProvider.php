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
use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Services\McpToolService;
use App\PncAgentics\Contracts\FileSaverInterface;
use App\PncAgentics\Services\FileSaver;

class PncAgenticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Agent::class);

        $this->app->bind(SkillExecutor::class, function ($app) {
            return new SkillExecutor(
                $app->make(
                    \App\PncServices\Contracts\AIServiceInterface::class,
                ),
            );
        });

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

        $this->app->singleton(FileSaverInterface::class, FileSaver::class);

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

    public function boot(): void
    {
        // FIX: Instead of calling ->make() eagerly, pass a fallback closure.
        // This delays the file-scanning loop until you actually request config('pnc.skills')
        config()->set("pnc.skills", function () {
            return app("pnc.skills");
        });
    }
}
