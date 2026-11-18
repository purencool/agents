<?php

namespace App\PncTemplates\Engine;

/**
 * Immutable value object representing a parsed markdown document.
 */
class MarkdownDocument
{
    /**
     * @param array<string, string> $sections Keyed by H2 heading
     */
    public function __construct(
        public readonly string $title,
        public readonly string $introduction,
        public readonly array $sections,
        public readonly string $conclusion,
        public readonly string $raw,
    ) {}

    /**
     * Assembles all body sections (excluding Conclusion) into a single string.
     */
    public function body(): string
    {
        $parts = [];
        foreach ($this->sections as $heading => $body) {
            if (strtolower($heading) === "conclusion") {
                continue;
            }
            $parts[] = "## {$heading}\n\n{$body}";
        }
        return implode("\n\n", $parts);
    }

    /**
     * Returns a specific section by heading, or empty string.
     */
    public function section(string $heading): string
    {
        return $this->sections[$heading] ?? "";
    }
}
