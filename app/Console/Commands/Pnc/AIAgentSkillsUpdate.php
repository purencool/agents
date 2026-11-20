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
use InvalidArgumentException;

/**
 * Artisan command that creates or updates a markdown skill file on disk.
 *
 * Accepts a JSON payload via --input with "skill" (dot-notation key) and
 * "content" (markdown string). Delegates all file resolution, directory
 * creation, and persistence to SkillRegistryInterface.
 *
 * If the skill key already exists, the content is updated.
 * If the skill key does not exist, a new skill is created.
 *
 * @example php artisan pnc:agent:skills:update --input='{"skill":"communications_pr.internal_communications.skills","content":"You are an AI assistant for internal communications."}'
 */
class AIAgentSkillsUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pnc:agent:skills:update {--input= : JSON string containing "skill" and "content"}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Create or update the markdown content of an AI Agent skill.";

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
     * Validates the JSON input, then delegates to SkillRegistryInterface:
     *  - If the skill key already exists, calls update().
     *  - If the skill key does not exist, calls create().
     *
     * @return int Command::SUCCESS (0) or Command::FAILURE (1).
     */
    public function handle(): int
    {
        $rawInput = $this->option("input");

        if (!$rawInput) {
            $this->error(
                "No input provided. Please provide a JSON string using the --input option.",
            );
            return self::FAILURE;
        }

        $data = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Invalid JSON: " . json_last_error_msg());
            return self::FAILURE;
        }

        $skillName = $data["skill"] ?? null;
        $content = $data["content"] ?? null;

        if (!$skillName) {
            $this->error('Missing "skill" key in JSON payload.');
            return self::FAILURE;
        }

        if ($content === null) {
            $this->error('Missing "content" key in JSON payload.');
            return self::FAILURE;
        }

        try {
            if ($this->registry->get($skillName) !== null) {
                $this->registry->update($skillName, $content);
                $this->info("Successfully updated skill: {$skillName}");
            } else {
                $this->registry->create($skillName, $content);
                $this->info("Successfully created skill: {$skillName}");
            }

            return self::SUCCESS;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->error("Failed to write skill: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
