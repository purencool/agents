<?php

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;

class AIAgentSkills extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pnc:agent:skills {--pretty : Format the JSON output to be human-readable}';

    /**
     * The console command description.
     */
    protected $description = 'List all available AI Agent skills in JSON format.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $skills = config('pnc.skills');

        if (empty($skills)) {
            $this->error(json_encode(['error' => 'No skills found. Ensure PncAgenticsServiceProvider is registered.']));
            return self::FAILURE;
        }

        $skillNames = array_keys($skills);

        $response = [
            'count' => count($skillNames),
            'skills' => $skillNames
        ];

        if ($this->option('pretty')) {
            $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(json_encode($response, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
