<?php

namespace App\PncServices\Agent\Actions;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CreateAgentStructureAction
{
    public function execute(array $data): string
    {
        $domain = Str::slug($data["domain"], "_");
        $contentTrack = Str::slug($data["content_track"], "_");
        $type = Str::slug($data["type"], "_");
        $titleSlug = Str::slug($data["title"], "_");
        $currentDate = now()->format("Y-m-d");
        $uuid = (string) Str::uuid();

        $overlayBaseStore = env("OVERLAY_OVERRIDES");
        if ($overlayBaseStore) {
            $baseDataStore = realpath(
                base_path($overlayBaseStore) . "/../data",
            );
        }

        if (empty($baseDataStore) || !$baseDataStore) {
            $baseDataStore = base_path("../data/agents/data");
        }

        $trackDirectory = "{$baseDataStore}/{$uuid}/{$domain}/{$contentTrack}/{$type}";
        $typeConfigPath = "{$trackDirectory}/config";

        $dateDirectory = "{$trackDirectory}/{$currentDate}";
        $titleDirectory = "{$dateDirectory}/{$titleSlug}";
        $researchPath = "{$titleDirectory}/research";
        $stagePath = "{$titleDirectory}/stage";
        $devPath = "{$titleDirectory}/dev";

        if (is_dir($trackDirectory)) {
            $dateDirectories = array_filter(
                glob("{$trackDirectory}/*"),
                "is_dir",
            );

            foreach ($dateDirectories as $dateDir) {
                if (basename($dateDir) === "config") {
                    continue;
                }

                if (is_dir("{$dateDir}/{$titleSlug}")) {
                    throw new InvalidArgumentException(
                        "A directory for title '{$data["title"]}' already exists under track '{$data["content_track"]}' with execution type '{$data["type"]}'.",
                    );
                }
            }
        }

        if (!is_dir($typeConfigPath) && !mkdir($typeConfigPath, 0755, true)) {
            throw new RuntimeException(
                "Failed to create type configuration path: {$typeConfigPath}",
            );
        }

        // Create research dir (sibling of titleSlug)
        if (!is_dir($researchPath) && !mkdir($researchPath, 0755, true)) {
            throw new RuntimeException(
                "Failed to create research path: {$researchPath}",
            );
        }

        if (!is_dir($stagePath) && !mkdir($stagePath, 0755, true)) {
            throw new RuntimeException(
                "Failed to create stage path: {$stagePath}",
            );
        }

        if (!is_dir($devPath) && !mkdir($devPath, 0755, true)) {
            throw new RuntimeException("Failed to create dev path: {$devPath}");
        }

        $markdownContent = "# {$data["title"]}\n\n## Description\n{$data["description"]}\n\n---\nCreated automatically via Pnc Agent Engine on {$currentDate}.";
        file_put_contents("{$titleDirectory}/master.md", $markdownContent);

        file_put_contents(
            "{$titleDirectory}/payload.json",
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        return $titleDirectory;
    }
}
