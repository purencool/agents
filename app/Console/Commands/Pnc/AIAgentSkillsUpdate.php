<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Artisan command that updates or creates a markdown skill file on disk.
 *
 * Accepts a JSON payload via --input with "skill" (dot-notation key) and
 * "content" (markdown string). Converts the dot-notation key to a file
 * path under app/PncAgentics/Skills/, creates intermediate directories
 * if needed, and writes the content.
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
    protected $description = "Update or create the markdown content of an AI Agent skill.";

    /**
     * Execute the console command.
     *
     * Validates the JSON input, resolves the dot-notation skill key to a
     * file path, creates parent directories if necessary, and writes the
     * markdown content.
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

        $relativePath =
            str_replace(".", DIRECTORY_SEPARATOR, $skillName) . ".md";

        $basePath = app_path("PncAgentics/Skills");
        $fullPath = $basePath . DIRECTORY_SEPARATOR . $relativePath;

        $directory = dirname($fullPath);
        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }

        $success = File::put($fullPath, $content);

        if ($success !== false) {
            $this->info("Successfully updated skill: {$skillName}");
            $this->line("File saved at: {$fullPath}");
            return self::SUCCESS;
        } else {
            $this->error("Failed to write to file: {$fullPath}");
            return self::FAILURE;
        }
    }
}
