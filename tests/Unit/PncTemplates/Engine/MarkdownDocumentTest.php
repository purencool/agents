<?php

declare(strict_types=1);

namespace Tests\Unit\PncTemplates\Engine;

use App\PncTemplates\Engine\MarkdownDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MarkdownDocument.
 *
 * Verifies:
 *  - Readonly property assignment
 *  - body() assembly (heading format, joining, conclusion exclusion)
 *  - body() with empty / conclusion-only sections
 *  - section() lookup (found, not found, case sensitivity)
 */
final class MarkdownDocumentTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // Constructor / readonly properties
    // ─────────────────────────────────────────────────────────────

    /**
     * All constructor parameters are assigned to their corresponding
     * readonly properties without modification.
     */
    #[Test]
    public function constructor_assigns_all_properties(): void
    {
        $doc = new MarkdownDocument(
            title: "My Title",
            introduction: "Intro text",
            sections: ["Alpha" => "alpha body", "Beta" => "beta body"],
            conclusion: "Conclusion text",
            raw: "# My Title\n\nIntro text\n\n## Alpha\n\nalpha body",
        );

        $this->assertSame("My Title", $doc->title);
        $this->assertSame("Intro text", $doc->introduction);
        $this->assertSame(
            ["Alpha" => "alpha body", "Beta" => "beta body"],
            $doc->sections,
        );
        $this->assertSame("Conclusion text", $doc->conclusion);
        $this->assertSame(
            "# My Title\n\nIntro text\n\n## Alpha\n\nalpha body",
            $doc->raw,
        );
    }

    // ─────────────────────────────────────────────────────────────
    // body()
    // ─────────────────────────────────────────────────────────────

    /**
     * body() prefixes each section with its H2 heading and joins
     * sections with double newlines.
     */
    #[Test]
    public function body_assembles_sections_with_headings(): void
    {
        $doc = new MarkdownDocument(
            title: "T",
            introduction: "",
            sections: [
                "Overview" => "The overview text.",
                "Details" => "The details text.",
            ],
            conclusion: "",
            raw: "",
        );

        $expected =
            "## Overview\n\nThe overview text.\n\n## Details\n\nThe details text.";

        $this->assertSame($expected, $doc->body());
    }

    /**
     * A section whose heading is "Conclusion" (any case) is excluded
     * from the body() output.
     */
    #[Test]
    public function body_excludes_conclusion_section_case_insensitive(): void
    {
        $doc = new MarkdownDocument(
            title: "T",
            introduction: "",
            sections: [
                "Body" => "Body text.",
                "Conclusion" => "Should be excluded.",
            ],
            conclusion: "",
            raw: "",
        );

        $this->assertSame("## Body\n\nBody text.", $doc->body());
    }

    /**
     * A section whose heading is lowercase "conclusion" is also
     * excluded, confirming case-insensitive matching.
     */
    #[Test]
    public function body_excludes_conclusion_with_lowercase_heading(): void
    {
        $doc = new MarkdownDocument(
            title: "T",
            introduction: "",
            sections: [
                "Body" => "Body text.",
                "conclusion" => "Should be excluded.",
            ],
            conclusion: "",
            raw: "",
        );

        $this->assertSame("## Body\n\nBody text.", $doc->body());
    }

    /**
     * When sections is an empty array, body() returns an empty string.
     */
    #[Test]
    public function body_returns_empty_string_when_no_sections(): void
    {
        $doc = new MarkdownDocument(
            title: "T",
            introduction: "Only intro",
            sections: [],
            conclusion: "",
            raw: "",
        );

        $this->assertSame("", $doc->body());
    }

    /**
     * When the only section is "Conclusion", body() returns an empty
     * string because all sections are filtered out.
     */
    #[Test]
    public function body_returns_empty_string_when_only_conclusion(): void
    {
        $doc = new MarkdownDocument(
            title: "T",
            introduction: "",
            sections: ["Conclusion" => "Just a conclusion."],
            conclusion: "Just a conclusion.",
            raw: "",
        );

        $this->assertSame("", $doc->body());
    }

    /**
     * body() preserves the insertion order of sections as they appear
     * in the sections array.
     */
    #[Test]
    public function body_preserves_section_order(): void
    {
        $doc = new MarkdownDocument(
            title: "T",
            introduction: "",
            sections: [
                "First" => "1",
                "Second" => "2",
                "Third" => "3",
            ],
            conclusion: "",
            raw: "",
        );

        $this->assertSame(
            "## First\n\n1\n\n## Second\n\n2\n\n## Third\n\n3",
            $doc->body(),
        );
    }

    // ─────────────────────────────────────────────────────────────
    // section()
    // ─────────────────────────────────────────────────────────────

    /**
     * section() returns the body text for an existing heading.
     */
    #[Test]
    public function section_returns_matching_heading_body(): void
    {
        $doc = new MarkdownDocument(
            title: "T",
            introduction: "",
            sections: ["Alpha" => "alpha content", "Beta" => "beta content"],
            conclusion: "",
            raw: "",
        );

        $this->assertSame("alpha content", $doc->section("Alpha"));
        $this->assertSame("beta content", $doc->section("Beta"));
    }

    /**
     * section() returns an empty string when the heading does not exist.
     */
    #[Test]
    public function section_returns_empty_string_for_missing_heading(): void
    {
        $doc = new MarkdownDocument(
            title: "T",
            introduction: "",
            sections: ["Alpha" => "alpha content"],
            conclusion: "",
            raw: "",
        );

        $this->assertSame("", $doc->section("Nonexistent"));
    }

    /**
     * section() performs an exact (case-sensitive) key lookup, unlike
     * body() which uses case-insensitive matching for "conclusion".
     */
    #[Test]
    public function section_is_case_sensitive(): void
    {
        $doc = new MarkdownDocument(
            title: "T",
            introduction: "",
            sections: ["My Section" => "content here"],
            conclusion: "",
            raw: "",
        );

        $this->assertSame("content here", $doc->section("My Section"));
        $this->assertSame("", $doc->section("my section"));
    }
}
