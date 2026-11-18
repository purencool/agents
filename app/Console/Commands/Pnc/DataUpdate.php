<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\Console\Commands\Pnc;

use App\PncTemplates\Contracts\TemplateTransformerInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Artisan command that batch-updates existing content track files.
 *
 * Accepts a raw JSON payload as a positional argument containing:
 *  - "domain": The target domain string.
 *  - "title":  The article/asset title.
 *  - "contentTracks": An associative array mapping track names to template filenames.
 *
 * Iterates over each track, delegates to the TemplateTransformer, and
 * reports success/skip/failure per track.
 *
 * @example php artisan pnc:agent:data:update '{"domain":"example.com","title":"My Article","contentTracks":{"blog":"blog_template","social":"social_template"}}'
 */
class DataUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "pnc:agent:data:update {data : The raw JSON string object}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Accepts a JSON payload to batch update existing content track files";

    /**
     * @param TemplateTransformerInterface $transformer Handles per-track template transformation.
     */
    public function __construct(
        protected TemplateTransformerInterface $transformer,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Parses the JSON argument, validates required keys, iterates over
     * content tracks, and delegates each to the transformer.
     *
     * @return int Command::SUCCESS (0) or Command::FAILURE (1).
     */
    public function handle(): int
    {
        $totalJobStart = microtime(true);

        $decoded = json_decode($this->argument("data"), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Invalid JSON: " . json_last_error_msg());
            return self::FAILURE;
        }

        $domain = $decoded["domain"] ?? null;
        $title = $decoded["title"] ?? null;
        $contentTracks = $decoded["contentTracks"] ?? null;

        if ($domain === null || $title === null || $contentTracks === null) {
            $this->error(
                'JSON payload must contain "domain", "title", and "contentTracks" keys.',
            );
            return self::FAILURE;
        }

        $this->info("Initiating content update pipeline:");
        $this->line(" - Domain: " . $domain);
        $this->line(" - Title:  " . $title);
        $this->line(str_repeat("=", 80));

        $payload = [
            "domain" => $domain,
            "title" => $title,
            "contentTracks" => $contentTracks,
        ];

        foreach ($contentTracks as $trackName => $templateFilename) {
            $this->comment(
                "Processing update for track [{$trackName}] via template [{$templateFilename}]",
            );

            try {
                $result = $this->transformer->transform(
                    $templateFilename,
                    $payload,
                    $payload,
                );

                $this->info("Updated: {$result["target_directory"]}");
            } catch (InvalidArgumentException $e) {
                $this->warn("Skipped [{$trackName}]: " . $e->getMessage());
                continue;
            } catch (\Exception $e) {
                $this->error("Fatal [{$trackName}]: " . $e->getMessage());
                return self::FAILURE;
            }
        }

        $duration = round(microtime(true) - $totalJobStart, 2);
        Log::channel("daily")->info(
            "[BATCH UPDATE COMPLETED] Update pipeline finished in {$duration}s",
        );

        $this->line(str_repeat("=", 80));
        $this->info("Batch update pipeline completed in {$duration}s");
        return self::SUCCESS;
    }
}
