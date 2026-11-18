<?php

namespace App\PncTemplates\Engine;

/**
 * Renders a MarkdownDocument into template-defined fields and HTML elements.
 */
class TemplateRenderer
{
    /**
     * @param array $template    Decoded template JSON (fields + elements)
     * @param array $contentData Decoded content.json for this track
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

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) <= $max
            ? $text
            : mb_substr($text, 0, $max - 3) . "...";
    }

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
                "{$key}",
                htmlspecialchars($value, ENT_QUOTES),
                $output,
            );
        }
        return $output;
    }

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
