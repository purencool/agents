<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Actions;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the full directory and file structure for a multi-domain,
 * multi-type, multi-language content generation request.
 *
 * Resulting structure:
 *
 * {baseDataStore}/
 * ├── domains/{year}/{uuid}/{dateTime}/
 * │   ├── assets/
 * │   ├── {domainSlug}-{titleSlug}-{typeSlug}-{lang}-content.json
 * │   ├── {domainSlug}-{titleSlug}-{typeSlug}-{lang}-content.md
 * │   ├── {domainSlug}-{titleSlug}-{typeSlug}-{lang}-description.md
 * │   └── ...
 * └── research/{year}/{uuid}/{dateTime}/
 *     └── research.json
 *
 * The UUID is shared between domains/ and research/ for cross-referencing.
 * Each comma-separated language in a track's "lang" field produces its own file set.
 *
 * @see \App\PncServices\Contracts\AgentDataInterface::createStructure()
 */
class CreateAgentStructureAction
{
    /**
     * Base filesystem path where agent data is stored.
     *
     * Resolved to {project_root}/../data/agents/data at runtime.
     */
    protected string $basePath;

    public function __construct()
    {
        $this->basePath = base_path("../data/agents/data");
    }

    /**
     * Builds the full directory tree and seed files for the given payload.
     *
     * For each domain → type → language combination, three files are created:
     *   - content.json   (structured metadata + empty content placeholders)
     *   - content.md     (empty, to be filled by template/research stages)
     *   - description.md (seeded with the payload description)
     *
     * @param array{
     *     title: string,
     *     description?: string,
     *     domains: array<string, array{
     *         context?: string,
     *         fields: array<string, array{
     *             content_tracks?: array{
     *                 config?: array{
     *                     workflow?: string,
     *                     template?: string,
     *                     track?: string,
     *                     type?: string,
     *                     lang?: string
     *                 }
     *             }
     *         }>
     *     }>>
     * } $data Validated payload with title, optional description, and domain/track config.
     *
     * @return array{
     *     uuid: string,
     *     domain_dir: string,
     *     assets_dir: string,
     *     research_dir: string,
     *     tracks: array<string, array{
     *         domain: string,
     *         type: string,
     *         template: string,
     *         track: string,
     *         track_type: string,
     *         workflow: string,
     *         lang: string,
     *         content_json: string,
     *         content_md: string,
     *         description_md: string
     *     }>
     * }
     *
     * @throws RuntimeException If a directory cannot be created.
     */
    public function execute(array $data): array
    {
        $title = $data["title"];
        $titleSlug = Str::slug($title, "_");
        $description = $data["description"] ?? "";
        $domains = $data["domains"] ?? [];

        $categories = array_map(
            "trim",
            explode(",", $data["categories"] ?? "none"),
        );

        $now = now();
        $year = $now->format("Y");
        $dateTime = $now->format("Y_m_d_His");
        $uuid = (string) Str::uuid();

        $domainDir = "{$this->basePath}/content/{$year}/{$uuid}/{$dateTime}";
        $assetsDir = "{$domainDir}/assets";
        $researchDir = "{$this->basePath}/content/{$year}/{$uuid}/{$dateTime}/research";

        $this->ensureDirectory($domainDir);
        $this->ensureDirectory($assetsDir);
        $this->ensureDirectory($researchDir);

        $trackFiles = [];

        foreach ($domains as $domain => $types) {
            $domainSlug = Str::slug($domain, "_");

            // No more "fields" wrapper — $types IS the type map
            foreach ($types as $type => $typeConfig) {
                $typeSlug = Str::slug($type, "_");

                // context is now per-type
                $context = $typeConfig["context"] ?? "";

                $trackCfg = $typeConfig["content_tracks"]["config"] ?? [];

                $workflow = $trackCfg["workflow"] ?? "default";
                $template = $trackCfg["template"] ?? "social";
                $track = $trackCfg["track"] ?? "social_media";
                $trackType = $trackCfg["type"] ?? "social";

                $langs = array_map(
                    "trim",
                    explode(",", $trackCfg["lang"] ?? "en"),
                );

                foreach ($langs as $lang) {
                    $fileBase = "{$domainSlug}-{$titleSlug}-{$typeSlug}-{$lang}";
                    $this->ensureDirectory("{$domainDir}/{$domainSlug}");
                    $this->ensureDirectory(
                        "{$domainDir}/{$domainSlug}/{$typeSlug}-{$lang}",
                    );
                    $contentJsonPath = "{$domainDir}/{$domainSlug}/{$typeSlug}-{$lang}/{$fileBase}-content.json";
                    $contentMdPath = "{$domainDir}/{$domainSlug}/{$typeSlug}-{$lang}/{$fileBase}-content.md";
                    $descriptionMdPath = "{$domainDir}/{$domainSlug}/{$typeSlug}-{$lang}/{$fileBase}-description.md";

                    // Key includes lang to avoid overwriting multi-lang entries
                    $trackFiles["{$domain}"]["{$typeSlug}-{$lang}"] = [
                        "uuid" => $uuid,
                        "title" => $title,
                        "categories" => $categories,
                        "workflow" => $workflow,
                        "lang" => $lang,
                        "domain" => $domain,
                        "context" => $context,
                        "type" => $type,
                        "template" => $template,
                        "track" => $track,
                        "track_type" => $trackType,
                        "content_json" => "",
                        "content_path_json" => $contentJsonPath,
                        "content_html" => "",
                        "content_path_html" => "",
                        "content_markdown" => "",
                        "content_path_markdown" => $contentMdPath,
                        "content_description" => $descriptionMdPath,
                    ];

                    file_put_contents(
                        $contentJsonPath,
                        json_encode(
                            [
                                [
                                    "content" =>
                                        $trackFiles["{$domain}"][
                                            "{$typeSlug}-{$lang}"
                                        ],
                                ],
                            ],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                        ),
                    );
                    file_put_contents($contentMdPath, "");
                    file_put_contents($descriptionMdPath, $description);
                }
            }
        }

        $structure = [
            "uuid" => $uuid,
            "title" => $title,
            "title_slug" => $titleSlug,
            "description" => $description,
            "categories" => $categories,
            "domain_dir" => $domainDir,
            "assets_dir" => $assetsDir,
            "research_dir" => $researchDir,
            "tracks" => $trackFiles,
        ];

        return $structure;
    }

    /**
     * Creates the directory (and parents) if it does not already exist.
     *
     * @param string $path Absolute path to the directory.
     *
     * @throws RuntimeException If mkdir() fails.
     */
    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true)) {
            throw new RuntimeException("Failed to create directory: {$path}");
        }
    }
}
