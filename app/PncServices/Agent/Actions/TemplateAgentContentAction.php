<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\PncServices\Agent\Actions;

use App\PncServices\Contracts\ContentPersistenceInterface;
use App\PncTemplates\Contracts\TemplateTransformerInterface;
use Illuminate\Support\Facades\Concurrency;

/**
 * Orchestrates parallel template transformation for all tracks.
 *
 * Contains NO transformation logic — only concurrency, data loading,
 * and error recovery. All "what to do with each track" lives in
 * TemplateTransformerService.
 *
 * Execution strategy:
 *  1. Builds one closure per track from the structure array.
 *  2. Attempts parallel execution via Concurrency::driver("fork").
 *  3. Falls back to sequential execution if the driver is "sync"
 *     or if the parallel run throws.
 *  4. In fallback mode, per-track exceptions are caught and returned
 *     as error strings without aborting the remaining tracks.
 */
class TemplateAgentContentAction
{
    /**
     * @param TemplateTransformerInterface $transformer        Handles per-track transformation.
     * @param ContentPersistenceInterface  $contentPersistence Reads content JSON files.
     */
    public function __construct(
        private TemplateTransformerInterface $transformer,
        private ContentPersistenceInterface $contentPersistence,
    ) {}

    /**
     * Execute template transformation for all tracks in the structure.
     *
     * Each track's content JSON is loaded, passed to the transformer
     * along with the track info, and the result is persisted.
     *
     * @param array $structure Pipeline structure containing a "tracks" key,
     *                         where each value is a track info array with at
     *                         minimum: template, content_json, content_md.
     *
     * @return array<string, string> Result messages keyed by track name.
     *                               Success: "Success: [key] saved at [dir]."
     *                               Failure: "Error: [key] [message]"
     */
    public function execute(array $structure): array
    {
        $tasks = [];

        foreach ($structure["tracks"] as $key => $trackInfo) {
            $tasks[$key] = function () use ($trackInfo, $key): string {
                $contentData =
                    $this->contentPersistence->readJson(
                        $trackInfo["content_json"],
                    ) ?? [];

                $result = $this->transformer->transform(
                    $trackInfo["template"],
                    $trackInfo,
                    $contentData,
                );

                return "Success: [{$key}] saved at [{$result["target_directory"]}].";
            };
        }

        $timeout = (int) config("pncservices.ai.timeout", 600);

        try {
            if (config("concurrency.default") === "sync") {
                throw new \RuntimeException("Concurrency driver is sync.");
            }

            return Concurrency::driver("fork")->run($tasks, $timeout);
        } catch (\Throwable) {
            return $this->runSequentially($tasks);
        }
    }

    /**
     * Execute all tasks sequentially, catching per-track exceptions.
     *
     * Used as a fallback when the parallel driver is unavailable or fails.
     * A failure in one track does not prevent remaining tracks from running.
     *
     * @param array<string, \Closure> $tasks Callable tasks keyed by track name.
     *
     * @return array<string, string> Result or error message per track.
     */
    private function runSequentially(array $tasks): array
    {
        $results = [];

        foreach ($tasks as $key => $task) {
            try {
                $results[$key] = $task();
            } catch (\Exception $e) {
                $results[$key] = "Error: [{$key}] " . $e->getMessage();
            }
        }

        return $results;
    }
}
