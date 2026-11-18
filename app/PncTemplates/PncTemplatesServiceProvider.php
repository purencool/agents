<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\PncTemplates;

use Illuminate\Support\ServiceProvider;
use App\PncTemplates\Contracts\TemplateTransformerInterface;
use App\PncTemplates\Services\TemplateTransformerService;

/**
 * Service provider for the PNC Templates package.
 *
 * register():
 *  - Binds TemplateTransformerInterface to TemplateTransformerService
 *    in the application container (non-singleton).
 *
 * boot():
 *  - Merges the package's config/templates.php into the application
 *    config under the "templates" key, if the file exists.
 */
class PncTemplatesServiceProvider extends ServiceProvider
{
    /**
     * Register service bindings in the container.
     */
    public function register(): void
    {
        $this->app->bind(
            TemplateTransformerInterface::class,
            TemplateTransformerService::class,
        );
    }

    /**
     * Boot the package: merge configuration if available.
     */
    public function boot(): void
    {
        if (file_exists(__DIR__ . "/config/templates.php")) {
            $this->mergeConfigFrom(
                __DIR__ . "/config/templates.php",
                "templates",
            );
        }
    }
}
