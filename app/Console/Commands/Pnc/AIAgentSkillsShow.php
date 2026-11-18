<?php

declare(strict_types=1);

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;

/**
 * Artisan command that displays the raw markdown content of a single AI Agent skill.
 *
 * Reads the skill registry from config('pnc.skills') (populated by
 * PncAgenticsServiceProvider) and prints the file contents for the
 * requested dot-notation key.
 *
 * @example php artisan pnc:agent:skills:show developer_docs.core_writing.hook_introduction_writer
 */
class AIAgentSkillsShow extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Uses a positional {skill} argument (not an option) for faster typing.
     *
     * @var string
     */
    protected $signature = "pnc:agent:skills:show {skill : The dot-notated path of the skill to show}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Show the markdown content of a specific AI Agent skill.";

    /**
     * Execute the console command.
     *
     * Resolves the skill key from config('pnc.skills') and prints its
     * raw markdown content. Returns FAILURE if the skills registry is
     * empty or the requested key does not exist.
     *
     * @return int Command::SUCCESS (0) or Command::FAILURE (1).
     */
    public function handle(): int
    {
        $skillRequest = $this->argument("skill");
        $skills = config("pnc.skills");

        if (empty($skills)) {
            $this->error(
                "No skills found. Ensure PncAgenticsServiceProvider is registered.",
            );
            return self::FAILURE;
        }

        if (!array_key_exists($skillRequest, $skills)) {
            $this->error("Skill '{$skillRequest}' not found.");
            $this->info(
                "Tip: Use 'php artisan agent:skills' to see a list of available skills.",
            );
            return self::FAILURE;
        }

        $this->line($skills[$skillRequest]);

        return self::SUCCESS;
    }
}
