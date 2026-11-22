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
 * With no argument, lists all registered skills (table by default).
 * With a dot-notation key, prints the raw markdown content of that skill.
 *
 * Options:
 *  - --json : Output as JSON (for third-party services)
 *  - --raw  : Output as plain text (grep-friendly)
 *
 * Delegates all skill resolution to SkillRegistryInterface, keeping
 * this command as a pure display layer.
 *
 * @example php artisan pnc:agent:skills:show
 * @example php artisan pnc:agent:skills:show --json
 * @example php artisan pnc:agent:skills:show --raw | grep "communications"
 * @example php artisan pnc:agent:skills:show developer_docs.core_writing.hook_introduction_writer
 */
class AIAgentSkillsShow extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "pnc:agent:skills:show {skill? : The dot-notation key of the skill to show (omit to list all)} {--json : Output as JSON} {--raw : Output as plain text (grep-friendly)}";

    /**
     * The command description.
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
            if ($this->option("json")) {
                $this->line(
                    json_encode(
                        $skills,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    ),
                );
                return self::SUCCESS;
            }

            if ($this->option("raw")) {
                return $this->listAllRaw($skills);
            }

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

        if ($this->option("json")) {
            $this->line(
                json_encode(
                    ["key" => $skillKey, "content" => $content],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ),
            );
            return self::SUCCESS;
        }

        if ($this->option("raw")) {
            $this->line($this->resolveContent($content));
            return self::SUCCESS;
        }

        // Default: print content as-is
        $this->line($this->resolveContent($content));

        return self::SUCCESS;
    }

    /**
     * Render a table of all registered skills (default view).
     *
     * @param array<string, mixed> $skills
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
            $rows[] = [$key, $this->contentSize($content) . " chars"];
        }

        $this->table(["Key", "Size"], $rows);
        $this->newLine();
        $this->info("Usage: php artisan pnc:agent:skills:show <key>");

        return self::SUCCESS;
    }

    /**
     * Render a grep-friendly plain-text list of all skills.
     *
     * @param array<string, mixed> $skills
     *
     * @return int Always Command::SUCCESS.
     */
    protected function listAllRaw(array $skills): int
    {
        foreach ($skills as $key => $content) {
            $this->line("{$key}\t{$this->contentSize($content)}");
        }

        return self::SUCCESS;
    }

    /**
     * Get the display size of a skill's content.
     *
     * @param mixed $content
     *
     * @return int
     */
    protected function contentSize(mixed $content): int
    {
        if (is_array($content)) {
            return strlen($content["system_instructions"] ?? "");
        }
        return strlen((string) $content);
    }

    /**
     * Resolve content to a printable string.
     *
     * @param mixed $content
     *
     * @return string
     */
    protected function resolveContent(mixed $content): string
    {
        if (is_array($content)) {
            return $content["system_instructions"] ??
                json_encode($content, JSON_PRETTY_PRINT);
        }
        return (string) $content;
    }
}
