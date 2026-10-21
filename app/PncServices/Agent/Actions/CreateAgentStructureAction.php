<?php

namespace App\PncServices\Agent\Actions;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CreateAgentStructureAction
{
    /**
     * Executes the filesystem hierarchy creation logic matching the:
     * domain -> content_track -> type -> date -> title structure.
     *
     * @param array $data Validated payload data
     * @return string The newly generated absolute path
     * @throws InvalidArgumentException|RuntimeException
     */
    public function execute(array $data): string
    {
        // Sanitize/Normalize names for filesystem safety rules
        $domain = Str::slug($data["domain"], "_");
        $contentTrack = Str::slug($data["content_track"], "_");
        $type = Str::slug($data["type"], "_");
        $titleSlug = Str::slug($data["title"], "_");
        $currentDate = now()->format("Y-m-d");

        // Falls back seamlessly to the standard historical relative
        // root folder path if omitted
        $overlayBaseStore = env("OVERLAY_OVERRIDES");
        if ($overlayBaseStore) {
            // Traverse up out of the 'overrides' prompt text leaf
            // directory to find the parallel 'data' sibling node
            $baseDataStore = realpath(
                base_path($overlayBaseStore) . "/../data",
            );
        }

        // Final fallback guard matching your design tracking layout
        // configuration target
        if (empty($baseDataStore) || !$baseDataStore) {
            $baseDataStore = base_path("../data/agents/data");
        }

        // Establishes paths strictly ordering content_track right
        // before the business type parameter layout
        $trackDirectory = "{$baseDataStore}/{$domain}/{$contentTrack}/{$type}";
        $typeConfigPath = "{$trackDirectory}/config";

        $titleDirectory = "{$trackDirectory}/{$currentDate}/{$titleSlug}";
        $stagePath = "{$titleDirectory}/stage";
        $devPath = "{$titleDirectory}/dev";

        // Scan the isolated tracking branch directory to ensure this
        // title isn't already installed under ANY date path
        if (is_dir($trackDirectory)) {
            $dateDirectories = array_filter(
                glob("{$trackDirectory}/*"),
                "is_dir",
            );

            foreach ($dateDirectories as $dateDir) {
                if (basename($dateDir) === "config") {
                    continue; // Skip the dynamic configuration node safely
                }

                if (is_dir("{$dateDir}/{$titleSlug}")) {
                    throw new InvalidArgumentException(
                        "A directory for title '{$data["title"]}' already exists under track '{$data["content_track"]}' with execution type '{$data["type"]}'.",
                    );
                }
            }
        }

        // Create the config directory safely under the track/type
        // context if it doesn't exist
        if (!is_dir($typeConfigPath) && !mkdir($typeConfigPath, 0755, true)) {
            throw new RuntimeException(
                "Failed to create type configuration path: {$typeConfigPath}",
            );
        }

        // Create the stage and dev directories under 'title'
        if (!is_dir($stagePath) && !mkdir($stagePath, 0755, true)) {
            throw new RuntimeException(
                "Failed to create stage path: {$stagePath}",
            );
        }

        if (!is_dir($devPath) && !mkdir($devPath, 0755, true)) {
            throw new RuntimeException("Failed to create dev path: {$devPath}");
        }

        // Generate the default master.md fallback canvas file
        $markdownContent = "# {$data["title"]}\n\n## Description\n{$data["description"]}\n\n---\nCreated automatically via Pnc Agent Engine on {$currentDate}.";
        file_put_contents("{$titleDirectory}/master.md", $markdownContent);

        // Save the raw JSON data payload trace matching parameters metrics precisely
        file_put_contents(
            "{$titleDirectory}/payload.json",
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        return $titleDirectory;
    }
}
