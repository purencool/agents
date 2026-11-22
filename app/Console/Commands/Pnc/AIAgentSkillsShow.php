<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\Console\Commands\Pnc;

use App\PncAgentics\Contracts\SkillRegistryInterface;
use Illuminate\Console\Command;

/**
 * Artisan command that displays AI Agent skills.
 *
 * With no argument, lists all registered skills in a table.
 * With a dot-notation key, prints the raw markdown content of that skill.
 *
 * Delegates all skill resolution to SkillRegistryInterface, keeping
 * this command as a pure display layer.
 *
 * @example php artisan pnc:agent:skills:show
 * @example php artisan pnc:agent:skills:show developer_docs.core_writing.hook_introduction_writer
 */
class AIAgentSkillsShow extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "pnc:agent:skills:show {skill? : The dot-notation key of the skill to show (omit to list all)}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "List all AI Agent skills or show the markdown content of a specific skill.";

    /**
     * @param SkillRegistryInterface $registry The skill registry service.
     */
    public function __construct(protected SkillRegistryInterface $registry)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * If no skill key is provided, renders a table of all registered skills
     * with their keys and content sizes. If a key is provided, prints the
     * raw markdown content for that skill.
     *
     * @return int Command::SUCCESS (0) or Command::FAILURE (1).
     */
    public function handle(): int
    {
        $skills = $this->registry->all();

        if (empty($skills)) {
            $this->error(
                "No skills found. Ensure PncAgenticsServiceProvider is registered.",
            );
            return self::FAILURE;
        }

        $skillKey = $this->argument("skill");

        // No argument — list all skills
        if ($skillKey === null) {
            return $this->listAll($skills);
        }

        // Specific skill requested
        $content = $this->registry->get($skillKey);

        if ($content === null) {
            $this->error("Skill '{$skillKey}' not found.");
            $this->info(
                "Tip: Run 'php artisan pnc:agent:skills:show' (no argument) to list all skills.",
            );
            return self::FAILURE;
        }

        $this->line($content);

        return self::SUCCESS;
    }

    /**
     * Render a table of all registered skills.
     *
     * @param array<string, string> $skills Map of skill key to content.
     *
     * @return int Always Command::SUCCESS.
     */
    protected function listAll(array $skills): int
    {
        $count = count($skills);
        $this->info("Available skills ({$count}):");
        $this->newLine();

        $rows = [];
        foreach ($skills as $key => $content) {
            $rows[] = [
                $key,
                strlen($content["system_instructions"] ?? "") . " chars",
            ];
        }

        $this->table(["Key", "Size"], $rows);

        $this->newLine();
        $this->info("Usage: php artisan pnc:agent:skills:show <key>");

        return self::SUCCESS;
    }
}
