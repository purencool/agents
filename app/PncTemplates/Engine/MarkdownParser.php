<?php

namespace App\PncTemplates\Engine;

/**
 * Parses raw markdown text into a structured MarkdownDocument.
 */
class MarkdownParser
{
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

    private function extractTitle(string $markdown): string
    {
        if (preg_match('/^# (.+)$/m', $markdown, $m)) {
            return trim($m[1]);
        }
        return "";
    }

    /**
     * @return array<string, string>
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
