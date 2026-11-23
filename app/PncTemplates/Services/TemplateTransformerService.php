<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncTemplates\Services;

use App\PncTemplates\Contracts\TemplateTransformerInterface;
use App\PncTemplates\Engine\MarkdownParser;
use App\PncTemplates\Engine\TemplateRenderer;
use App\PncServices\Contracts\ContentPersistenceInterface;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Orchestrates the template transformation pipeline for a single track.
 *
 * Receives the workflow_results array (from JsonWorkflowExecutor) directly
 * in memory — no disk read for content. Resolves and loads the template
 * blueprint, delegates parsing/rendering to the engine, and persists
 * output artifacts via ContentPersistenceInterface.
 *
 * Pipeline:
 *  1. Receives $contentData (workflow_results array) — no I/O.
 *  2. Resolves and loads the template blueprint JSON from disk.
 *  3. Delegates parsing to MarkdownParser (if content is markdown).
 *  4. Delegates rendering to TemplateRenderer.
 *  5. Persists output artifacts via ContentPersistenceInterface.
 *
 * Template resolution order (first match wins):
 *  1. config("pnc.templates.override_dir") — integration team overlay.
 *  2. config("pnc.templates.templates_dir") — app-level templates.
 *  3. "default.json" in the templates directory.
 */
class TemplateTransformerService implements TemplateTransformerInterface
{
    /**
     * @param ContentPersistenceInterface $contentPersistence Reads/writes template and output files.
     * @param MarkdownParser              $parser             Parses markdown content into a document structure.
     * @param TemplateRenderer            $renderer           Renders the document against a template blueprint.
     */
    public function __construct(
        private ContentPersistenceInterface $contentPersistence,
        private MarkdownParser $parser,
        private TemplateRenderer $renderer,
    ) {}

    /**
     * Transforms the generated content for a single track.
     *
     * The $contentData parameter is the workflow_results array produced by
     * JsonWorkflowExecutor — passed in-memory, not read from disk.
     *
     * @param string $templateFilename Template name without extension (e.g. "blog_post").
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
     * } $trackInfo Track metadata from the pipeline structure.
     *
     * @param array $contentData The workflow_results array (structured LLM output).
     *
     * @return array{
     *     target_directory: string,
     *     extracted_fields: array,
     *     html_elements: array<string, string>
     * }
     *
     * @throws InvalidArgumentException When the template cannot be resolved.
     */
    public function transform(
        string $templateFilename,
        array $trackInfo,
        array $contentData,
    ): array {
        $template = $this->loadTemplate($templateFilename);

        // Parse markdown if the content data contains a "text" key (raw string),
        // otherwise pass the structured array directly to the renderer.
        if (isset($contentData["text"]) && is_string($contentData["text"])) {
            $doc = $this->parser->parse($contentData["text"]);
        } else {
            $doc = $contentData;
        }

        $result = $this->renderer->render($doc, $template, $contentData);

        $targetDir = dirname($trackInfo["content_md"]);

        // Persist output artifacts.
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
     * Uses File::get() directly — templates are static JSON files on disk,
     * not user content. ContentPersistenceInterface is reserved for
     * pipeline content (which may move to a database in the future).
     *
     * @param string $filename Template name without extension.
     *
     * @return array The decoded template blueprint.
     *
     * @throws InvalidArgumentException When the template is not found or contains invalid JSON.
     */
    private function loadTemplate(string $filename): array
    {
        $path = $this->resolveTemplatePath($filename);

        $raw = File::get($path);
        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                "Template at {$path} is not valid JSON: " .
                    json_last_error_msg(),
            );
        }

        return $data;
    }

    /**
     * Cascading template path resolution.
     *
     * Resolution order:
     *  1. config("pnc.templates.override_dir") — integration team overlay
     *     (set via OVERLAY_TEMPLATES env var).
     *  2. config("pnc.templates.templates_dir") — app-level templates.
     *  3. "default.json" in the templates directory.
     *
     * @param string $filename Template name without extension.
     *
     * @return string Absolute path to the resolved template file.
     *
     * @throws InvalidArgumentException When no template is found in any location.
     */
    private function resolveTemplatePath(string $filename): string
    {
        $file = $filename . ".json";

        // Override directory (integration team overlay).
        $overrideDir = config("pnc.templates.override_dir");
        if ($overrideDir) {
            $overridePath =
                base_path($overrideDir) . DIRECTORY_SEPARATOR . $file;
            if (File::exists($overridePath)) {
                return $overridePath;
            }
        }

        // App-level templates directory.
        $templatesDir = config("pnc.templates.templates_dir");
        $appPath = $templatesDir . DIRECTORY_SEPARATOR . $file;
        if (File::exists($appPath)) {
            return $appPath;
        }

        // Default fallback.
        $defaultPath = $templatesDir . DIRECTORY_SEPARATOR . "default.json";
        if (File::exists($defaultPath)) {
            return $defaultPath;
        }

        throw new InvalidArgumentException(
            "Could not resolve template '{$file}'. Searched: " .
                (string) ($overrideDir
                    ? base_path($overrideDir)
                    : "(no override)") .
                ", " .
                $templatesDir,
        );
    }
}
