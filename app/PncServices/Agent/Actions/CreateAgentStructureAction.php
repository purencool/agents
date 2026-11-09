<?php

namespace App\PncServices\Agent\Actions;

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
     *     domains: array<string, array<string, array{
     *         content_tracks?: array{
     *             config?: array{
     *                 workflow?: string,
     *                 template?: string,
     *                 track?: string,
     *                 type?: string,
     *                 lang?: string
     *             }
     *         }
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

        $now = now();
        $year = $now->format("Y");
        $dateTime = $now->format("Y_m_d_His");
        $uuid = (string) Str::uuid();

        $domainDir = "{$this->basePath}/domains/{$year}/{$uuid}/{$dateTime}";
        $assetsDir = "{$domainDir}/assets";
        $researchDir = "{$this->basePath}/research/{$year}/{$uuid}/{$dateTime}";

        $this->ensureDirectory($domainDir);
        $this->ensureDirectory($assetsDir);
        $this->ensureDirectory($researchDir);

        file_put_contents(
            "{$researchDir}/research.json",
            json_encode(
                ["uuid" => $uuid],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        $trackFiles = [];

        foreach ($domains as $domain => $types) {
            $domainSlug = Str::slug($domain, "_");

            foreach ($types as $type => $typeConfig) {
                $typeSlug = Str::slug($type, "_");
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

                    $contentJsonPath = "{$domainDir}/{$fileBase}-content.json";
                    $contentMdPath = "{$domainDir}/{$fileBase}-content.md";
                    $descriptionMdPath = "{$domainDir}/{$fileBase}-description.md";

                    $contentData = [
                        "uuid" => $uuid,
                        "description" => $description,
                        "title" => $title,
                        "domain" => $domain,
                        "type" => $type,
                        "template" => $template,
                        "track" => $track,
                        "track_type" => $trackType,
                        "workflow" => $workflow,
                        "lang" => $lang,
                        "html_content" => "",
                        "markdown_content" => "",
                        "fields" => [],
                    ];

                    file_put_contents(
                        $contentJsonPath,
                        json_encode(
                            $contentData,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                        ),
                    );
                    file_put_contents($contentMdPath, "");
                    file_put_contents($descriptionMdPath, $description);

                    $trackFiles["{$domain}/{$type}/{$lang}"] = [
                        "domain" => $domain,
                        "type" => $type,
                        "template" => $template,
                        "track" => $track,
                        "track_type" => $trackType,
                        "workflow" => $workflow,
                        "lang" => $lang,
                        "content_json" => $contentJsonPath,
                        "content_md" => $contentMdPath,
                        "description_md" => $descriptionMdPath,
                    ];
                }
            }
        }

        return [
            "uuid" => $uuid,
            "domain_dir" => $domainDir,
            "assets_dir" => $assetsDir,
            "research_dir" => $researchDir,
            "tracks" => $trackFiles,
        ];
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
