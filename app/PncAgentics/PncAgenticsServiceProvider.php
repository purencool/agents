<?php

namespace App\PncAgentics;

use Illuminate\Support\ServiceProvider;
use App\PncAgentics\Agents\Agent;

class PncAgenticsServiceProvider extends ServiceProvider
{
    
    /**
     *
     */
    public function register(): void
    {
        $this->app->bind(Agent::class);
    
        // Configuration creation
        $this->app->singleton('pnc.skills', function () {
            $base = app_path('PncAgentics/Skills');
            $skills = [];

        foreach (glob($base . '/*', GLOB_ONLYDIR) as $dir) {
            foreach (glob($dir . '/*.md') as $file) {
                $name = basename($file, '.md');  // "SKILL.md" → "SKILL"
                $skills[$name] = file_get_contents($file);
            }
        }

            return $skills;
        });
    }

    /**
     *
     */
    public function boot(): void
    {
        config(['pnc.skills' => $this->app->make('pnc.skills')]);
    }
}