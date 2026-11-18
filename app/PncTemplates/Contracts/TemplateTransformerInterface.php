<?php

declare(strict_types=1);

namespace App\PncTemplates\Contracts;

/**
 * Contract for transforming generated markdown content into
 * structured, type-safe output using a template blueprint.
 */
interface TemplateTransformerInterface
{
    /**
     * Transforms the generated markdown for a single track into
     * structured fields and HTML elements using the resolved template.
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
     * } $trackInfo The track entry from the pipeline structure.
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
    ): array;
}
