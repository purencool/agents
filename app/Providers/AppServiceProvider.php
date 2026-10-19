<?php
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
        // Fetch the path from your .env file
        $envPath = env("AGENT_DATA_STORE");

        if ($envPath) {
            // Resolve the relative path accurately against the Laravel base path
            $resolvedPath = base_path($envPath);

            // Check if the directory does NOT exist
            if (!is_dir($resolvedPath)) {
                // Handle the missing directory (e.g., auto-create it)
                mkdir($resolvedPath, 0755, true);

                Log::info(
                    "AGENT_DATA_STORE directory did not exist. Created at: {$resolvedPath}",
                );
            }
        }
    }
}
