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
use App\PncTemplates\Engine\MarkdownDocument;
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
 * blueprint via File::get(), delegates parsing/rendering to the engine,
 * and persists output artifacts via ContentPersistenceInterface.
 *
 * Pipeline:
 *  1. Receives $contentData (workflow_results array) — no I/O.
 *  2. Resolves and loads the template blueprint JSON from disk via File::get().
 *  3. Produces a MarkdownDocument:
 *     - If $contentData has a "text" key (string) → parse via MarkdownParser.
 *     - Otherwise → construct from structured keys (title, introduction, sections, conclusion).
 *  4. Delegates rendering to TemplateRenderer.
 *  5. Persists output artifacts via ContentPersistenceInterface.
 *
 * Template resolution order (first match wins):
 *  1. config("pnc.templates.override_dir") — integration team overlay
 *     (resolved via base_path(), so must be a relative path).
 *  2. config("pnc.templates.templates_dir") — app-level templates.
 *  3. "default.json" in the templates directory.
 *
 * @see \App\PncTemplates\Contracts\TemplateTransformerInterface
 * @see \App\PncAgentics\Actions\TemplateAgentContentAction
 */
class TemplateTransformerService implements TemplateTransformerInterface
{
    /**
     * @param ContentPersistenceInterface $contentPersistence Persists output artifacts.
     * @param MarkdownParser              $parser             Parses markdown content into a MarkdownDocument.
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
     * The $contentData parameter is the workflow_results entry for this track,
     * produced by JsonWorkflowExecutor — passed in-memory, not read from disk.
     *
     * Two input shapes are supported:
     *  - ["text" => "raw markdown string"] → parsed via MarkdownParser.
     *  - ["title" => ..., "introduction" => ..., "sections" => [...], "conclusion" => ...]
     *    → constructed directly into a MarkdownDocument.
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
     *     context: string,
     *     content_path_json: string,
     *     content_path_markdown: string,
     *     content_description: string
     * } $trackInfo Track metadata from the pipeline structure.
     *
     * @param array $contentData The workflow_results entry for this track
     *                           (either ["text" => string] or structured array).
     *
     * @return array{
     *     target_directory: string,
     *     extracted_fields: array,
     *     html_elements: array<string, string>
     * }
     *
     * @throws InvalidArgumentException When the template cannot be resolved
     *                                   or contains invalid JSON.
     */
    public function transform(
        string $templateFilename,
        array $trackInfo,
        array $contentData,
    ): array {
        $template = $this->loadTemplate($templateFilename);

        // Always produce a MarkdownDocument for the renderer.
        $doc = $this->buildDocument($contentData);

        $result = $this->renderer->render($doc, $template, $contentData);

        $targetDir = dirname($trackInfo["content_path_markdown"]);

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
     * Build a MarkdownDocument from the content data.
     *
     * If the data contains a "text" key with a string value, it is parsed
     * as markdown. Otherwise, a document is constructed from the structured
     * keys (title, introduction, sections, conclusion, full_content).
     *
     * @param array $contentData The workflow output for this track.
     *
     * @return MarkdownDocument
     */
    private function buildDocument(array $contentData): MarkdownDocument
    {
        if (isset($contentData["text"]) && is_string($contentData["text"])) {
            return $this->parser->parse($contentData["text"]);
        }

        return new MarkdownDocument(
            title: $contentData["title"] ?? "",
            introduction: $contentData["introduction"] ?? "",
            sections: $contentData["sections"] ?? [],
            conclusion: $contentData["conclusion"] ?? "",
            raw: $contentData["full_content"] ?? "",
        );
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
     *     (resolved via base_path(), so must be a relative path).
     *  2. config("pnc.templates.templates_dir") — app-level templates
     *     (absolute path, typically set via base_path() in config).
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
