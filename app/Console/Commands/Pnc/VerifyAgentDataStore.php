<?php

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;

class VerifyAgentDataStore extends Command
{
    // The name and signature of the console command
    protected $signature = "pnc:agent:verify-store";

    // The console command description
    protected $description = "Checks if the AGENT_DATA_STORE directory exists and creates it if missing";

    public function handle()
    {
        $envPath = env("AGENT_DATA_STORE");

        if (!$envPath) {
            $this->error(
                "AGENT_DATA_STORE variable is not defined in your .env file.",
            );
            return Command::FAILURE;
        }

        $resolvedPath = base_path($envPath);

        if (is_dir($resolvedPath)) {
            $this->info("Success: The directory exists at [{$resolvedPath}].");
            return Command::SUCCESS;
        }

        $this->warn("Directory missing. Attempting to create: {$resolvedPath}");

        if (mkdir($resolvedPath, 0755, true)) {
            $this->info("Directory successfully created!");
            return Command::SUCCESS;
        }

        $this->error(
            "Failed to create the directory. Check your folder permissions.",
        );
        return Command::FAILURE;
    }
}
