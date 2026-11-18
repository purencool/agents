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
 * Immutable value object representing a parsed markdown document.
 *
 * Holds the structured output of MarkdownParser: a title, an introduction,
 * H2-keyed sections, a conclusion, and the raw source text.
 *
 * All properties are readonly — instances are created once and never mutated.
 */
class MarkdownDocument
{
    /**
     * @param string              $title        The document title (H1 or front-matter).
     * @param string              $introduction Text before the first H2 heading.
     * @param array<string,string> $sections    Section bodies keyed by H2 heading text.
     * @param string              $conclusion   The conclusion section body.
     * @param string              $raw          The original unparsed markdown source.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $introduction,
        public readonly array $sections,
        public readonly string $conclusion,
        public readonly string $raw,
    ) {}

    /**
     * Assembles all body sections (excluding "conclusion", case-insensitive)
     * into a single markdown string with H2 headings.
     *
     * @return string Concatenated sections, each prefixed with "## {heading}\n\n",
     *                separated by double newlines.
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
     * Returns the body text of a specific section by heading.
     *
     * @param string $heading The H2 heading to look up.
     * @return string The section body, or an empty string if not found.
     */
    public function section(string $heading): string
    {
        return $this->sections[$heading] ?? "";
    }
}
