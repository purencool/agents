<?php

declare(strict_types=1);

namespace App\PncTemplates\Engine;

/**
 * Parses raw markdown text into a structured MarkdownDocument.
 *
 * Extraction rules:
 *  - Title:        First H1 heading (`# ...`)
 *  - Introduction: All text between the H1 and the first H2 (exclusive)
 *  - Sections:     All H2 headings and their body text, keyed by heading
 *  - Conclusion:   The specific "## Conclusion" section body
 *  - Raw:          The original unparsed markdown string
 */
class MarkdownParser
{
    /**
     * Parse raw markdown into a MarkdownDocument.
     *
     * @param string $markdown The full markdown source text.
     * @return MarkdownDocument Structured representation of the document.
     */
    public function parse(string $markdown): MarkdownDocument
    {
        $title = $this->extractTitle($markdown);
        $sections = $this->extractSections($markdown);
        $introduction = $this->extractIntroduction($markdown);
        $conclusion = $this->extractConclusion($markdown);

        return new MarkdownDocument(
            $title,
            $introduction,
            $sections,
            $conclusion,
            $markdown,
        );
    }

    /**
     * Extract the H1 title from the markdown.
     *
     * @return string The title text, or empty string if no H1 is found.
     */
    private function extractTitle(string $markdown): string
    {
        if (preg_match('/^# (.+)$/m', $markdown, $m)) {
            return trim($m[1]);
        }
        return "";
    }

    /**
     * Extract all H2 sections as an associative array.
     *
     * @return array<string, string> Section bodies keyed by H2 heading text.
     */
    private function extractSections(string $markdown): array
    {
        $sections = [];
        $parts = preg_split(
            '/^## (.+)$/m',
            $markdown,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        for ($i = 1; $i < count($parts) - 1; $i += 2) {
            $sections[trim($parts[$i])] = trim($parts[$i + 1]);
        }

        return $sections;
    }

    /**
     * Extract the introduction — text between the H1 and the first H2.
     *
     * If no H2 exists, the entire markdown (minus the first line) is
     * returned as the introduction.
     *
     * @return string The introduction text, trimmed.
     */
    private function extractIntroduction(string $markdown): string
    {
        $pos = strpos($markdown, "\n## ");
        if ($pos === false) {
            return trim($markdown);
        }

        $lines = explode("\n", substr($markdown, 0, $pos));
        array_shift($lines);
        return trim(implode("\n", $lines));
    }

    /**
     * Extract the body of the "## Conclusion" section specifically.
     *
     * @return string The conclusion body, or empty string if not found.
     */
    private function extractConclusion(string $markdown): string
    {
        if (
            preg_match('/^## Conclusion\s*$(.*?)(?=^## |\z)/ms', $markdown, $m)
        ) {
            return trim($m[1]);
        }
        return "";
    }
}
