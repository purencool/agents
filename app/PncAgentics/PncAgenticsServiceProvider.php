<?php

namespace App\PncAgentics;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\PncAgentics\Agents\Agent;
use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Services\SkillExecutor;
use App\PncAgentics\Contracts\DocumentationWorkflowInterface;
use App\PncAgentics\Workflows\GenerateDocumentationWorkflow;

class PncAgenticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Agent::class);
        $this->app->bind(SkillExecutorInterface::class, SkillExecutor::class);
        $this->app->bind(
            DocumentationWorkflowInterface::class,
            GenerateDocumentationWorkflow::class,
        );

        // Configuration creation (Keep your exact loop)
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
                        $key = str_replace(["/", "\\"], ".", $pathWithoutExt);
                        $skills[$key] = $file->getContents();
                    }
                }
            }
            return $skills;
        });
    }

    public function boot(): void
    {
        config(["pnc.skills" => $this->app->make("pnc.skills")]);
    }
}
