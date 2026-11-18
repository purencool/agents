<?php

namespace App\PncTemplates\Services;

use App\PncTemplates\Contracts\TemplateTransformerInterface;
use App\PncTemplates\Engine\MarkdownParser;
use App\PncTemplates\Engine\TemplateRenderer;
use App\PncServices\Contracts\ContentPersistenceInterface;
use InvalidArgumentException;

/**
 * Orchestrates the template transformation pipeline for a single track.
 *
 * 1. Reads generated markdown via ContentPersistenceInterface
 * 2. Resolves and loads the template blueprint
 * 3. Delegates parsing to MarkdownParser
 * 4. Delegates rendering to TemplateRenderer
 * 5. Persists output artifacts via ContentPersistenceInterface
 */
class TemplateTransformerService implements TemplateTransformerInterface
{
    public function __construct(
        private ContentPersistenceInterface $contentPersistence,
        private MarkdownParser $parser,
        private TemplateRenderer $renderer,
    ) {}

    /**
     * Transforms the generated content for a single track.
     *
     * @param string $templateFilename Template name without extension (e.g. "blog_post")
     *
     * @param array{
     *     domain: string,
     *     type: string,
     *     template: string,
     *     track: string,
     *     track_type: string,
     *     workflow: string,
     *     lang: string,
     *     content_json: string,
     *     content_md: string,
     *     description_md: string,
     *     research_text: string
     * } $trackInfo
     *
     * @param array $contentData Decoded content.json for this track.
     *
     * @return array{
     *     target_directory: string,
     *     extracted_fields: array,
     *     html_elements: array<string, string>
     * }
     */
    public function transform(
        string $templateFilename,
        array $trackInfo,
        array $contentData,
    ): array {
        $markdown = $this->contentPersistence->readText(
            $trackInfo["content_md"],
        );

        if ($markdown === null) {
            throw new InvalidArgumentException(
                "Content markdown not found at: {$trackInfo["content_md"]}",
            );
        }

        $template = $this->loadTemplate($templateFilename);

        $doc = $this->parser->parse($markdown);
        $result = $this->renderer->render($doc, $template, $contentData);

        $targetDir = dirname($trackInfo["content_md"]);

        $this->contentPersistence->saveJson(
            "{$targetDir}/content.json",
            $result["raw_json_fields"],
        );

        foreach ($result["html_elements"] as $key => $html) {
            $this->contentPersistence->saveHtml(
                "{$targetDir}/element_{$key}.html",
                $html,
            );
        }

        return [
            "target_directory" => $targetDir,
            "extracted_fields" => $result["raw_json_fields"],
            "html_elements" => $result["html_elements"],
        ];
    }

    /**
     * Resolves and loads the template blueprint JSON.
     *
     * Uses ContentPersistenceInterface for consistency — when storage
     * moves to a database, templates resolve the same way.
     */
    private function loadTemplate(string $filename): array
    {
        $path = $this->resolveTemplatePath($filename);

        $data = $this->contentPersistence->readJson($path);

        if ($data === null) {
            throw new InvalidArgumentException(
                "Template at {$path} is not valid JSON.",
            );
        }

        return $data;
    }

    /**
     * Cascading template path resolution.
     */
    private function resolveTemplatePath(string $filename): string
    {
        $file = $filename . ".json";

        $overrideDir = rtrim((string) env("TEMPLATE_OVERRIDES", ""), "/");
        if (!empty($overrideDir) && file_exists("{$overrideDir}/{$file}")) {
            return "{$overrideDir}/{$file}";
        }

        $appPath = app_path("PncTemplates/Templates/{$file}");
        if (file_exists($appPath)) {
            return $appPath;
        }

        $defaultPath = app_path("PncTemplates/Templates/default.json");
        if (file_exists($defaultPath)) {
            return $defaultPath;
        }

        throw new InvalidArgumentException(
            "Could not resolve template '{$file}' in overrides, app templates, or default.",
        );
    }
}
