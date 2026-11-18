<?php

declare(strict_types=1);

namespace Tests\Unit\PncTemplates\Engine;

use App\PncTemplates\Engine\MarkdownDocument;
use App\PncTemplates\Engine\MarkdownParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MarkdownParser.
 *
 * Verifies:
 *  - Title extraction (present, absent, multiple H1s)
 *  - Section extraction (multiple, none, nested content)
 *  - Introduction extraction (with/without H2, empty)
 *  - Conclusion extraction (present, absent, followed by another section)
 *  - Full parse() integration returning a correct MarkdownDocument
 */
final class MarkdownParserTest extends TestCase
{
    private MarkdownParser $parser;

    /**
     * Instantiate the parser before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MarkdownParser();
    }

    // ─────────────────────────────────────────────────────────────
    // Title extraction
    // ─────────────────────────────────────────────────────────────

    /**
     * A single H1 heading is extracted as the document title.
     */
    #[Test]
    public function extracts_title_from_h1(): void
    {
        $doc = $this->parser->parse("# My Document Title\n\nSome content.");

        $this->assertSame("My Document Title", $doc->title);
    }

    /**
     * When no H1 heading exists, the title is an empty string.
     */
    #[Test]
    public function title_is_empty_when_no_h1(): void
    {
        $doc = $this->parser->parse("Just some text without a heading.");

        $this->assertSame("", $doc->title);
    }

    /**
     * Only the first H1 is used as the title; subsequent H1s are ignored.
     */
    #[Test]
    public function only_first_h1_is_used_as_title(): void
    {
        $markdown = "# First Title\n\nBody.\n\n# Second Title\n\nMore body.";

        $doc = $this->parser->parse($markdown);

        $this->assertSame("First Title", $doc->title);
    }

    // ─────────────────────────────────────────────────────────────
    // Section extraction
    // ─────────────────────────────────────────────────────────────

    /**
     * Multiple H2 sections are extracted as key-value pairs.
     */
    #[Test]
    public function extracts_multiple_h2_sections(): void
    {
        $markdown =
            "# Title\n\nIntro.\n\n## Section A\n\nContent A.\n\n## Section B\n\nContent B.\n\n## Section C\n\nContent C.";

        $doc = $this->parser->parse($markdown);

        $this->assertCount(3, $doc->sections);
        $this->assertSame("Content A.", $doc->sections["Section A"]);
        $this->assertSame("Content B.", $doc->sections["Section B"]);
        $this->assertSame("Content C.", $doc->sections["Section C"]);
    }

    /**
     * When no H2 headings exist, sections is an empty array.
     */
    #[Test]
    public function sections_is_empty_when_no_h2(): void
    {
        $doc = $this->parser->parse("# Title\n\nJust intro text.");

        $this->assertSame([], $doc->sections);
    }

    /**
     * Section body text is trimmed of leading/trailing whitespace.
     */
    #[Test]
    public function section_bodies_are_trimmed(): void
    {
        $markdown = "# T\n\n## My Section\n\n\n  Padded content.  \n\n";

        $doc = $this->parser->parse($markdown);

        $this->assertSame("Padded content.", $doc->sections["My Section"]);
    }

    /**
     * Multi-paragraph section content is preserved.
     */
    #[Test]
    public function section_preserves_multi_paragraph_content(): void
    {
        $markdown =
            "# T\n\n## Details\n\nParagraph one.\n\nParagraph two.\n\nParagraph three.";

        $doc = $this->parser->parse($markdown);

        $this->assertSame(
            "Paragraph one.\n\nParagraph two.\n\nParagraph three.",
            $doc->sections["Details"],
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Introduction extraction
    // ─────────────────────────────────────────────────────────────

    /**
     * Introduction is the text between H1 and the first H2.
     */
    #[Test]
    public function extracts_introduction_between_h1_and_first_h2(): void
    {
        $markdown =
            "# Title\n\nThis is the intro paragraph.\n\nMore intro.\n\n## First Section\n\nSection body.";

        $doc = $this->parser->parse($markdown);

        $this->assertSame(
            "This is the intro paragraph.\n\nMore intro.",
            $doc->introduction,
        );
    }

    /**
     * When no H2 exists, the entire markdown (including the H1 line)
     * is returned as the introduction.
     */
    #[Test]
    public function introduction_is_full_text_when_no_h2(): void
    {
        $markdown = "# Title\n\nOnly intro, no sections.";

        $doc = $this->parser->parse($markdown);

        $this->assertSame(
            "# Title\n\nOnly intro, no sections.",
            $doc->introduction,
        );
    }

    /**
     * When the markdown is just a title with no other content,
     * the introduction is the title line itself.
     */
    #[Test]
    public function introduction_is_title_line_when_only_title(): void
    {
        $doc = $this->parser->parse("# Title");

        $this->assertSame("# Title", $doc->introduction);
    }

    // ─────────────────────────────────────────────────────────────
    // Conclusion extraction
    // ─────────────────────────────────────────────────────────────

    /**
     * The "## Conclusion" section body is extracted as the conclusion.
     */
    #[Test]
    public function extracts_conclusion_section(): void
    {
        $markdown =
            "# T\n\nIntro.\n\n## Body\n\nBody text.\n\n## Conclusion\n\nThis is the conclusion.";

        $doc = $this->parser->parse($markdown);

        $this->assertSame("This is the conclusion.", $doc->conclusion);
    }

    /**
     * When no "## Conclusion" heading exists, conclusion is empty.
     */
    #[Test]
    public function conclusion_is_empty_when_not_present(): void
    {
        $markdown = "# T\n\nIntro.\n\n## Body\n\nBody text.";

        $doc = $this->parser->parse($markdown);

        $this->assertSame("", $doc->conclusion);
    }

    /**
     * Conclusion extraction stops at the next H2 if one follows.
     */
    #[Test]
    public function conclusion_stops_at_next_h2(): void
    {
        $markdown =
            "# T\n\n## Conclusion\n\nConclusion body.\n\n## After\n\nAfter body.";

        $doc = $this->parser->parse($markdown);

        $this->assertSame("Conclusion body.", $doc->conclusion);
    }

    // ─────────────────────────────────────────────────────────────
    // Raw preservation
    // ─────────────────────────────────────────────────────────────

    /**
     * The raw property always contains the original markdown unchanged.
     */
    #[Test]
    public function raw_contains_original_markdown(): void
    {
        $markdown = "# T\n\nIntro.\n\n## S\n\nBody.";

        $doc = $this->parser->parse($markdown);

        $this->assertSame($markdown, $doc->raw);
    }

    // ─────────────────────────────────────────────────────────────
    // Full integration
    // ─────────────────────────────────────────────────────────────

    /**
     * A complete document is parsed correctly into all fields.
     */
    #[Test]
    public function full_document_parse(): void
    {
        $markdown = <<<MD
        # Annual Report

        This is the introduction to the annual report.

        ## Revenue

        Revenue grew by 15% this year.

        ## Outlook

        We expect continued growth next year.

        ## Conclusion

        The outlook remains positive.
        MD;

        $doc = $this->parser->parse($markdown);

        $this->assertInstanceOf(MarkdownDocument::class, $doc);
        $this->assertSame("Annual Report", $doc->title);
        $this->assertSame(
            "This is the introduction to the annual report.",
            $doc->introduction,
        );
        $this->assertSame(
            "Revenue grew by 15% this year.",
            $doc->sections["Revenue"],
        );
        $this->assertSame(
            "We expect continued growth next year.",
            $doc->sections["Outlook"],
        );
        $this->assertSame("The outlook remains positive.", $doc->conclusion);
        $this->assertSame($markdown, $doc->raw);
    }

    /**
     * An empty string produces a document with all empty fields.
     */
    #[Test]
    public function empty_string_produces_empty_document(): void
    {
        $doc = $this->parser->parse("");

        $this->assertSame("", $doc->title);
        $this->assertSame("", $doc->introduction);
        $this->assertSame([], $doc->sections);
        $this->assertSame("", $doc->conclusion);
        $this->assertSame("", $doc->raw);
    }
}
