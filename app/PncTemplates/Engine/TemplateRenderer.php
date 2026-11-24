<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncTemplates\Engine;

/**
 * Renders a MarkdownDocument into template-defined JSON fields and HTML elements.
 *
 * The template is a decoded JSON array with two top-level keys:
 *  - "fields":   An array of field definitions mapping source → output key.
 *  - "elements": An array of HTML element definitions (template, meta_tags, social_card).
 *
 * Field sources:
 *  - "title"            → document title
 *  - "introduction"     → document introduction
 *  - "conclusion"       → document conclusion
 *  - "body"             → all non-conclusion sections assembled
 *  - "meta_description" → introduction truncated to 160 chars
 *  - "full_content"     → raw markdown
 *  - "content_data"     → JSON-encoded contentData
 *  - "section:<name>"   → a specific section by heading
 *  - anything else      → looked up in contentData by key
 *
 * Element types:
 *  - "template"     → string with {key} placeholders, HTML-escaped
 *  - "meta_tags"    → Open Graph meta tags
 *  - "social_card"  → social sharing card HTML
 *  - default        → empty string
 */
class TemplateRenderer
{
    /**
     * Render the document into JSON fields and HTML elements.
     *
     * @param MarkdownDocument $doc         The parsed markdown document.
     * @param array            $template    Decoded template JSON.
     * @param array            $contentData Decoded content.json for this track.
     *
     * @return array{raw_json_fields: array<string, string>, html_elements: array<string, string>}
     */
    public function render(
        MarkdownDocument $doc,
        array $template,
        array $contentData,
    ): array {
        $fields = $this->mapFields($doc, $template, $contentData);
        $elements = $this->renderElements($template, $fields, $doc->title);

        return [
            "raw_json_fields" => $fields,
            "html_elements" => $elements,
        ];
    }

    /**
     * Map each template field definition to its resolved string value.
     *
     * @return array<string, string>
     */
    private function mapFields(
        MarkdownDocument $doc,
        array $template,
        array $contentData,
    ): array {
        $fields = [];

        foreach ($template["fields"] ?? [] as $fieldDef) {
            $fields[$fieldDef["name"]] = match ($fieldDef["source"] ?? null) {
                "title" => $doc->title,
                "introduction" => $doc->introduction,
                "conclusion" => $doc->conclusion,
                "body" => $doc->body(),
                "meta_description" => $this->truncate($doc->introduction, 160),
                "full_content" => $doc->raw,
                "content_data" => json_encode($contentData),
                default => $this->resolveSource($fieldDef, $doc, $contentData),
            };
        }

        return $fields;
    }

    /**
     * Render each template element definition into its HTML output.
     *
     * @return array<string, string>
     */
    private function renderElements(
        array $template,
        array $fields,
        string $title,
    ): array {
        $elements = [];

        foreach ($template["elements"] ?? [] as $elementDef) {
            $elements[$elementDef["name"]] = match (
                $elementDef["type"] ?? "template"
            ) {
                "template" => $this->renderTemplate(
                    $elementDef["template"] ?? "",
                    $fields,
                    $title,
                ),
                "meta_tags" => $this->renderMetaTags(
                    $elementDef["fields"] ?? [],
                    $fields,
                    $title,
                ),
                "social_card" => $this->renderSocialCard($fields, $title),
                default => "",
            };
        }

        return $elements;
    }

    /**
     * Resolve a field source that doesn't match a built-in keyword.
     *
     * Supports "section:<heading>" for section lookup, otherwise
     * falls back to contentData key lookup.
     */
    private function resolveSource(
        array $fieldDef,
        MarkdownDocument $doc,
        array $contentData,
    ): string {
        $source = $fieldDef["source"] ?? "";

        if (str_starts_with($source, "section:")) {
            return $doc->section(substr($source, 8));
        }

        return $contentData[$source] ?? "";
    }

    /**
     * Truncate text to a maximum length, appending "..." if truncated.
     */
    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) <= $max
            ? $text
            : mb_substr($text, 0, $max - 3) . "...";
    }

    /**
     * Replace {key} placeholders in a template string with HTML-escaped field values.
     */
    private function renderTemplate(
        string $template,
        array $fields,
        string $title,
    ): string {
        $output = str_replace(
            "{title}",
            htmlspecialchars($title, ENT_QUOTES),
            $template,
        );
        foreach ($fields as $key => $value) {
            $output = str_replace(
                "{" . $key . "}",
                htmlspecialchars($value, ENT_QUOTES),
                $output,
            );
        }
        return $output;
    }

    /**
     * Generate Open Graph meta tags from the given field names.
     */
    private function renderMetaTags(
        array $fieldNames,
        array $fields,
        string $title,
    ): string {
        $tags = [
            '<meta property="og:title" content="' .
            htmlspecialchars($title, ENT_QUOTES) .
            '">',
        ];
        foreach ($fieldNames as $name) {
            $value = $fields[$name] ?? "";
            $tags[] =
                '<meta property="og:' .
                $name .
                '" content="' .
                htmlspecialchars($value, ENT_QUOTES) .
                '">';
        }
        return implode("\n", $tags);
    }

    /**
     * Generate a social sharing card HTML snippet.
     *
     * Uses "meta_description" field if available, otherwise falls back
     * to "introduction".
     */
    private function renderSocialCard(array $fields, string $title): string
    {
        $desc = $fields["meta_description"] ?? ($fields["introduction"] ?? "");
        return "<div class=\"social-card\">\n" .
            "  <h2>" .
            htmlspecialchars($title, ENT_QUOTES) .
            "</h2>\n" .
            "  <p>" .
            htmlspecialchars($desc, ENT_QUOTES) .
            "</p>\n" .
            "</div>";
    }
}
