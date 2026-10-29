<?php

namespace App\PncTemplates;

use Illuminate\Support\ServiceProvider;
use App\PncTemplates\Contracts\TemplateTransformerInterface;
use App\PncTemplates\Services\TemplateTransformerService;

class PncTemplatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            TemplateTransformerInterface::class,
            TemplateTransformerService::class,
        );
    }

    public function boot(): void
    {
        // Setup separate configuration baseline maps if required in future releases
        if (file_exists(__DIR__ . "/config/templates.php")) {
            $this->mergeConfigFrom(
                __DIR__ . "/config/templates.php",
                "templates",
            );
        }
    }
}
