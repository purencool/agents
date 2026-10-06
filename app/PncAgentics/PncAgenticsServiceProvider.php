<?php

namespace App\PncAgentics;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\PncAgentics\Agents\Agent;

class PncAgenticsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(Agent::class);

        // Configuration creation
        $this->app->singleton('pnc.skills', function () {
            $base = app_path('PncAgentics/Skills');
            $skills = [];

            if (File::isDirectory($base)) {
                foreach (File::allFiles($base) as $file) {
                    if ($file->getExtension() === 'md') {
                        $relativePath = $file->getRelativePathname();
                        $pathWithoutExt = Str::replaceLast('.md', '', $relativePath);
                        $key = str_replace(['/', '\\'], '.', $pathWithoutExt);
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
        config(['pnc.skills' => $this->app->make('pnc.skills')]);
    }
}
