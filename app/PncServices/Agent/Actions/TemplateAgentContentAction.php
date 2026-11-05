<?php

namespace App\PncServices\Agent\Actions;

use App\PncTemplates\Actions\ReframeAgentContentAction;
use Illuminate\Support\Facades\Concurrency;
use RuntimeException;

/**
 * Reads the file structure created by CreateAgentStructureAction and
 * dispatches AI content generation for each content.json found in the domain directory.
 */
class TemplateAgentContentAction
{
    public function __construct(
        protected ReframeAgentContentAction $reframeAction,
    ) {}

    /**
     * @param array $structure  Return value from CreateAgentStructureAction::execute()
     */
    public function execute(array $structure): array
    {
        $results = [];

        $tasks = [];
        foreach ($structure["tracks"] as $key => $trackInfo) {
            // Open files
            $tasks[$key] = function () use ($trackInfo, $structure) {
                $contentData = json_decode(
                    file_get_contents($trackInfo["content_json"]),
                    true,
                );

                $metrics = $this->reframeAction->execute(
                    $trackInfo["track"],
                    $trackInfo["template"],
                    $contentData,
                    $structure,
                    $trackInfo,
                );

                return "Success: [{$key}] saved at [{$metrics["target_directory"]}].";
            };
        }

        $timeoutCeiling = (int) config("pncservices.ai.timeout", 600);

        try {
            if (config("concurrency.default") === "sync") {
                throw new \RuntimeException("Concurrency driver is sync.");
            }

            $results = Concurrency::driver("fork")->run(
                $tasks,
                $timeoutCeiling,
            );
        } catch (\Throwable $e) {
            foreach ($tasks as $key => $taskClosure) {
                try {
                    $results[$key] = $taskClosure();
                } catch (\Exception $sub) {
                    $results[$key] = "Error: [{$key}] " . $sub->getMessage();
                }
            }
        }

        return $results;
    }

    /**
     * Reconstruct the structure array from a UUID on disk.
     * Allows calling execute() from a retry command, queue job, or REST endpoint
     * without needing the original CreateAgentStructureAction call in the same process.
     */
    public static function fromUuid(string $uuid): array
    {
        $baseDataStore = base_path("../data/agents/data");

        // Find the year directory that contains this UUID
        $domainDir = null;
        foreach (
            glob("{$baseDataStore}/domains/*/{$uuid}/*", GLOB_ONLYDIR)
            as $dir
        ) {
            $domainDir = $dir;
            break;
        }

        if (!$domainDir) {
            throw new RuntimeException(
                "No domain directory found for UUID: {$uuid}",
            );
        }

        // Derive year and research path
        $year = basename(dirname(dirname($domainDir)));
        $timestamp = basename($domainDir);
        $researchDir = "{$baseDataStore}/research/{$year}/{$uuid}/{$timestamp}";

        // Rebuild tracks from content.json files on disk
        $tracks = [];
        foreach (glob("{$domainDir}/*-content.json") as $jsonFile) {
            $data = json_decode(file_get_contents($jsonFile), true);
            $key = "{$data["domain"]}/{$data["type"]}/{$data["lang"]}";

            $tracks[$key] = [
                "domain" => $data["domain"],
                "type" => $data["type"],
                "template" => $data["template"],
                "track" => $data["track"],
                "track_type" => $data["track_type"],
                "lang" => $data["lang"],
                "content_json" => $jsonFile,
                "content_md" => str_replace(
                    "-content.json",
                    "-content.md",
                    $jsonFile,
                ),
                "description_md" => str_replace(
                    "-content.json",
                    "-description.md",
                    $jsonFile,
                ),
            ];
        }

        return [
            "uuid" => $uuid,
            "domain_dir" => $domainDir,
            "assets_dir" => "{$domainDir}/assets",
            "research_dir" => $researchDir,
            "tracks" => $tracks,
        ];
    }
}
