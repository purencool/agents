<?php

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;

class AIAgentSkillsShow extends Command
{
    /**
     * The name and signature of the console command.
     * We use an argument {skill} here instead of an option so it's faster to type.
     */
	protected $signature = 'pnc:agent:skills:show {skill : The dot-notated path of the skill to show}';

    /**
     * The console command description.
     */
    protected $description = 'Show the markdown content of a specific AI Agent skill.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $skillRequest = $this->argument('skill');
        $skills = config('pnc.skills');

        // Check if skills configuration loaded properly
        if (empty($skills)) {
            $this->error('No skills found. Ensure PncAgenticsServiceProvider is registered.');
            return self::FAILURE;
        }

        // Check if the requested skill exists in the array
        if (!array_key_exists($skillRequest, $skills)) {
            $this->error("Skill '{$skillRequest}' not found.");
            $this->info("Tip: Use 'php artisan agent:skills' to see a list of available skills.");
            return self::FAILURE;
        }

        // Output the raw markdown content of the skill
        $this->line($skills[$skillRequest]);

        return self::SUCCESS;
    }
}
