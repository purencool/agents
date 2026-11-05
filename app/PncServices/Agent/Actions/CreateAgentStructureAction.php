<?php

namespace App\PncServices\Agent\Actions;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the full directory and file structure for a multi-domain, multi-type, multi-language
 * content generation request.
 *
 * Resulting structure:
 *
 * {baseDataStore}/
 * ├── domains/2026/{uuid}/2026_11_04_143052/
 * │   ├── assets/
 * │   ├── example_com-test_document-typeone-en-content.json
 * │   ├── example_com-test_document-typeone-en-content.md
 * │   ├── example_com-test_document-typeone-en-description.md
 * │   ├── example_com-test_document-typeone-ru-content.json
 * │   ├── example_com-test_document-typeone-ru-content.md
 * │   ├── example_com-test_document-typeone-ru-description.md
 * │   ├── example_com-test_document-typetwo-en-content.json
 * │   ├── example_com-test_document-typetwo-en-content.md
 * │   ├── example_com-test_document-typetwo-en-description.md
 * │   ├── house_au-test_document-sales-en-content.json
 * │   ├── house_au-test_document-sales-en-content.md
 * │   ├── house_au-test_document-sales-en-description.md
 * │   ├── blog_com-test_document-blog-en-content.json
 * │   ├── blog_com-test_document-blog-en-content.md
 * │   ├── blog_com-test_document-blog-en-description.md
 * │   ├── meeting_com-test_document-meeting-en-content.json
 * │   ├── meeting_com-test_document-meeting-en-content.md
 * │   └── meeting_com-test_document-meeting-en-description.md
 * └── research/2026/{uuid}/2026_11_04_143052/
 *     └── research.json
 *
 * Naming convention: {domainSlug}-{titleSlug}-{typeSlug}-{lang}-content.json|.md
 *                    {domainSlug}-{titleSlug}-{typeSlug}-{lang}-description.md
 *
 * The UUID is shared between domains/ and research/ for cross-referencing.
 * Each comma-separated language in a track's "lang" field produces its own file set.
 */
class CreateAgentStructureAction
{
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

        $baseDataStore = base_path("../data/agents/data");

        $domainDir = "{$baseDataStore}/domains/{$year}/{$uuid}/{$dateTime}";
        $assetsDir = "{$domainDir}/assets";
        $researchDir = "{$baseDataStore}/research/{$year}/{$uuid}/{$dateTime}";

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
                $workflow = $trackCfg["workflow"] ?? "default"; // ← added

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

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true)) {
            throw new RuntimeException("Failed to create directory: {$path}");
        }
    }
}
