<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Actions;

use App\PncTemplates\Contracts\TemplateTransformerInterface;
use Illuminate\Support\Facades\Concurrency;

/**
 * Orchestrates parallel template transformation for all tracks.
 *
 * Receives the workflow_results array (from JsonWorkflowExecutor) and
 * passes it directly to the transformer per track — no disk I/O.
 */
class TemplateAgentContentAction
{
    /**
     * @param TemplateTransformerInterface $transformer Handles per-track transformation.
     */
    public function __construct(
        private TemplateTransformerInterface $transformer,
    ) {}

    /**
     * Execute template transformation for all tracks in the structure.
     *
     * Each track's content JSON is loaded, passed to the transformer
     * along with the track info, and the result is persisted.
     *
     * @param array
     *     uuid: string,
     *     domain_dir: string,
     *     assets_dir: string,
     *     research_dir: string,
     *     title: string,
     *     title_slug: string,
     *     workflow_result: array[]
     *     tracks: array<string, array[
     *         domain: string,
     *         type: string,
     *         template: string,
     *         track: string,
     *         track_type: string,
     *         workflow: string,
     *         lang: string,
     *         content_json: string,
     *         content_md: string,
     *         description_md: string,
     *         research_text: string
     *     ]>
     * ]
     *
     * @return array<string, string> Result messages keyed by track name.
     */
    public function execute(array $structure): array
    {
        $contentData = $structure["workflow_results"] ?? [];

        $tasks = [];

        foreach ($structure["tracks"] as $key => $trackInfo) {
            $tasks[$key] = function () use (
                $trackInfo,
                $key,
                $contentData,
            ): string {
                $result = $this->transformer->transform(
                    $trackInfo["template"],
                    $trackInfo,
                    $contentData,
                );

                return "Success: [{$key}] saved at [{$result["target_directory"]}].";
            };
        }

        $timeout = (int) config("pnc.ai.timeout", 600);

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
