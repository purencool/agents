<?php

namespace App\PncServices\Agent\Actions;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CreateAgentStructureAction
{
    /**
     * Executes the filesystem hierarchy creation logic.
     *
     * @param array $data Validated payload data
     * @return string The newly generated absolute path
     * @throws InvalidArgumentException|RuntimeException
     */
    public function execute(array $data): string
    {
        // Sanitize/Normalize names for filesystem safety
        $domain = Str::slug($data["domain"], "_");
        $type = Str::slug($data["type"], "_");
        $titleSlug = Str::slug($data["title"], "_");
        $currentDate = now()->format("Y-m-d");

        // Base directory configuration
        // @todo This needs to use the .env path
        $baseDataStore = base_path("../data/agents/data");

        // Target paths
        $typeDirectory = "{$baseDataStore}/{$domain}/{$type}";
        $typeConfigPath = "{$typeDirectory}/config";

        $titleDirectory = "{$typeDirectory}/{$currentDate}/{$titleSlug}";
        $stagePath = "{$titleDirectory}/stage";
        $devPath = "{$titleDirectory}/dev";

        // Scan the 'type' directory to ensure this title isn't already installed under ANY date path
        if (is_dir($typeDirectory)) {
            $dateDirectories = array_filter(
                glob("{$typeDirectory}/*"),
                "is_dir",
            );

            foreach ($dateDirectories as $dateDir) {
                if (is_dir("{$dateDir}/{$titleSlug}")) {
                    throw new InvalidArgumentException(
                        "A directory for title '{$data["title"]}' already exists under type '{$data["type"]}'.",
                    );
                }
            }
        }

        // Create the config directory safely under 'type' if it doesn't exist
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

        // Generate the master.md file
        $markdownContent = "# {$data["title"]}\n\n## Description\n{$data["description"]}\n\n---\nCreated automatically via Pnc Agent Engine on {$currentDate}.";
        file_put_contents("{$titleDirectory}/master.md", $markdownContent);

        // Save the raw JSON data payload
        file_put_contents(
            "{$titleDirectory}/payload.json",
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        return $titleDirectory;
    }
}
