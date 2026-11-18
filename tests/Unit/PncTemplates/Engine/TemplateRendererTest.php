<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace Tests\Unit\PncTemplates\Engine;

use App\PncTemplates\Engine\MarkdownDocument;
use App\PncTemplates\Engine\TemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TemplateRenderer.
 *
 * Verifies:
 *  - render() return structure
 *  - mapFields(): all source types (title, introduction, conclusion, body,
 *    meta_description, full_content, content_data, section:*, contentData fallback)
 *  - renderElements(): template, meta_tags, social_card, default
 *  - truncate(): short text unchanged, long text truncated with "..."
 *  - renderTemplate(): placeholder replacement + HTML escaping
 *  - renderMetaTags(): og:title + field tags
 *  - renderSocialCard(): uses meta_description or introduction fallback
 */
final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    /**
     * Instantiate the renderer before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new TemplateRenderer();
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Build a standard MarkdownDocument for testing.
     */
    private function makeDoc(): MarkdownDocument
    {
        return new MarkdownDocument(
            title: "Test Title",
            introduction: "This is the introduction text.",
            sections: [
                "Section A" => "Content of section A.",
                "Section B" => "Content of section B.",
                "Conclusion" => "The conclusion text.",
            ],
            conclusion: "The conclusion text.",
            raw: "# Test Title\n\nThis is the introduction text.\n\n## Section A\n\nContent of section A.",
        );
    }

    /**
     * A minimal template with one field and one element.
     */
    private function makeTemplate(): array
    {
        return [
            "fields" => [["name" => "title", "source" => "title"]],
            "elements" => [
                [
                    "name" => "header",
                    "type" => "template",
                    "template" => "<h1>{title}</h1>",
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // render() structure
    // ─────────────────────────────────────────────────────────────

    /**
     * render() returns an array with raw_json_fields and html_elements keys.
     */
    #[Test]
    public function render_returns_expected_structure(): void
    {
        $result = $this->renderer->render(
            $this->makeDoc(),
            $this->makeTemplate(),
            [],
        );

        $this->assertArrayHasKey("raw_json_fields", $result);
        $this->assertArrayHasKey("html_elements", $result);
        $this->assertIsArray($result["raw_json_fields"]);
        $this->assertIsArray($result["html_elements"]);
    }

    // ─────────────────────────────────────────────────────────────
    // mapFields() — source types
    // ─────────────────────────────────────────────────────────────

    /**
     * "title" source resolves to the document title.
     */
    #[Test]
    public function field_source_title(): void
    {
        $template = ["fields" => [["name" => "t", "source" => "title"]]];
        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame("Test Title", $result["raw_json_fields"]["t"]);
    }

    /**
     * "introduction" source resolves to the document introduction.
     */
    #[Test]
    public function field_source_introduction(): void
    {
        $template = [
            "fields" => [["name" => "intro", "source" => "introduction"]],
        ];
        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame(
            "This is the introduction text.",
            $result["raw_json_fields"]["intro"],
        );
    }

    /**
     * "conclusion" source resolves to the document conclusion.
     */
    #[Test]
    public function field_source_conclusion(): void
    {
        $template = [
            "fields" => [["name" => "conc", "source" => "conclusion"]],
        ];
        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame(
            "The conclusion text.",
            $result["raw_json_fields"]["conc"],
        );
    }

    /**
     * "body" source resolves to all non-conclusion sections assembled.
     */
    #[Test]
    public function field_source_body(): void
    {
        $template = ["fields" => [["name" => "body", "source" => "body"]]];
        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $expected =
            "## Section A\n\nContent of section A.\n\n## Section B\n\nContent of section B.";
        $this->assertSame($expected, $result["raw_json_fields"]["body"]);
    }

    /**
     * "meta_description" source truncates introduction to 160 chars.
     */
    #[Test]
    public function field_source_meta_description_truncates(): void
    {
        $longIntro = str_repeat("A", 200);
        $doc = new MarkdownDocument(
            title: "T",
            introduction: $longIntro,
            sections: [],
            conclusion: "",
            raw: "",
        );

        $template = [
            "fields" => [["name" => "desc", "source" => "meta_description"]],
        ];
        $result = $this->renderer->render($doc, $template, []);

        $this->assertSame(160, mb_strlen($result["raw_json_fields"]["desc"]));
        $this->assertStringEndsWith("...", $result["raw_json_fields"]["desc"]);
    }

    /**
     * "meta_description" does not truncate if introduction is short.
     */
    #[Test]
    public function field_source_meta_description_short_unchanged(): void
    {
        $template = [
            "fields" => [["name" => "desc", "source" => "meta_description"]],
        ];
        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame(
            "This is the introduction text.",
            $result["raw_json_fields"]["desc"],
        );
    }

    /**
     * "full_content" source resolves to the raw markdown.
     */
    #[Test]
    public function field_source_full_content(): void
    {
        $template = [
            "fields" => [["name" => "raw", "source" => "full_content"]],
        ];
        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame(
            $this->makeDoc()->raw,
            $result["raw_json_fields"]["raw"],
        );
    }

    /**
     * "content_data" source resolves to JSON-encoded contentData.
     */
    #[Test]
    public function field_source_content_data(): void
    {
        $contentData = ["key" => "value", "num" => 42];
        $template = [
            "fields" => [["name" => "data", "source" => "content_data"]],
        ];
        $result = $this->renderer->render(
            $this->makeDoc(),
            $template,
            $contentData,
        );

        $this->assertSame(
            json_encode($contentData),
            $result["raw_json_fields"]["data"],
        );
    }

    /**
     * "section:<name>" source resolves to a specific section body.
     */
    #[Test]
    public function field_source_section_prefix(): void
    {
        $template = [
            "fields" => [["name" => "sec", "source" => "section:Section A"]],
        ];
        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame(
            "Content of section A.",
            $result["raw_json_fields"]["sec"],
        );
    }

    /**
     * "section:<name>" for a non-existent section returns empty string.
     */
    #[Test]
    public function field_source_section_missing_returns_empty(): void
    {
        $template = [
            "fields" => [["name" => "sec", "source" => "section:Nonexistent"]],
        ];
        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame("", $result["raw_json_fields"]["sec"]);
    }

    /**
     * Unknown source falls back to contentData key lookup.
     */
    #[Test]
    public function field_source_falls_back_to_content_data_key(): void
    {
        $template = [
            "fields" => [["name" => "custom", "source" => "my_custom_key"]],
        ];
        $result = $this->renderer->render($this->makeDoc(), $template, [
            "my_custom_key" => "found it",
        ]);

        $this->assertSame("found it", $result["raw_json_fields"]["custom"]);
    }

    /**
     * Unknown source with no matching contentData key returns empty string.
     */
    #[Test]
    public function field_source_fallback_missing_key_returns_empty(): void
    {
        $template = ["fields" => [["name" => "custom", "source" => "nope"]]];
        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame("", $result["raw_json_fields"]["custom"]);
    }

    /**
     * When template has no "fields" key, raw_json_fields is empty.
     */
    #[Test]
    public function empty_fields_when_template_has_no_fields_key(): void
    {
        $template = ["elements" => []];
        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame([], $result["raw_json_fields"]);
    }

    // ─────────────────────────────────────────────────────────────
    // renderElements() — element types
    // ─────────────────────────────────────────────────────────────

    /**
     * "template" type replaces {key} placeholders with HTML-escaped values.
     */
    #[Test]
    public function element_type_template_replaces_placeholders(): void
    {
        $template = [
            "fields" => [
                ["name" => "title", "source" => "title"],
                ["name" => "intro", "source" => "introduction"],
            ],
            "elements" => [
                [
                    "name" => "out",
                    "type" => "template",
                    "template" => "<h1>{title}</h1><p>{intro}</p>",
                ],
            ],
        ];

        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame(
            "<h1>Test Title</h1><p>This is the introduction text.</p>",
            $result["html_elements"]["out"],
        );
    }

    /**
     * "template" type HTML-escapes field values.
     */
    #[Test]
    public function element_type_template_escapes_html(): void
    {
        $doc = new MarkdownDocument(
            title: "A <b>Bold</b> Title",
            introduction: "",
            sections: [],
            conclusion: "",
            raw: "",
        );

        $template = [
            "fields" => [["name" => "title", "source" => "title"]],
            "elements" => [
                [
                    "name" => "out",
                    "type" => "template",
                    "template" => "{title}",
                ],
            ],
        ];

        $result = $this->renderer->render($doc, $template, []);

        $this->assertSame(
            "A &lt;b&gt;Bold&lt;/b&gt; Title",
            $result["html_elements"]["out"],
        );
    }

    /**
     * "meta_tags" type generates og:title and field meta tags.
     */
    #[Test]
    public function element_type_meta_tags(): void
    {
        $template = [
            "fields" => [
                ["name" => "title", "source" => "title"],
                ["name" => "description", "source" => "introduction"],
            ],
            "elements" => [
                [
                    "name" => "meta",
                    "type" => "meta_tags",
                    "fields" => ["description"],
                ],
            ],
        ];

        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $expected =
            '<meta property="og:title" content="Test Title">' .
            "\n" .
            '<meta property="og:description" content="This is the introduction text.">';

        $this->assertSame($expected, $result["html_elements"]["meta"]);
    }

    /**
     * "meta_tags" type with no field names produces only og:title.
     */
    #[Test]
    public function element_type_meta_tags_only_title(): void
    {
        $template = [
            "fields" => [["name" => "title", "source" => "title"]],
            "elements" => [
                ["name" => "meta", "type" => "meta_tags", "fields" => []],
            ],
        ];

        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame(
            '<meta property="og:title" content="Test Title">',
            $result["html_elements"]["meta"],
        );
    }

    /**
     * "social_card" type uses meta_description field when available.
     */
    #[Test]
    public function element_type_social_card_uses_meta_description(): void
    {
        $template = [
            "fields" => [
                ["name" => "title", "source" => "title"],
                ["name" => "meta_description", "source" => "meta_description"],
            ],
            "elements" => [["name" => "card", "type" => "social_card"]],
        ];

        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertStringContainsString(
            "<h2>Test Title</h2>",
            $result["html_elements"]["card"],
        );
        $this->assertStringContainsString(
            "<p>This is the introduction text.</p>",
            $result["html_elements"]["card"],
        );
        $this->assertStringContainsString(
            'class="social-card"',
            $result["html_elements"]["card"],
        );
    }

    /**
     * "social_card" type falls back to introduction when meta_description is absent.
     */
    #[Test]
    public function element_type_social_card_falls_back_to_introduction(): void
    {
        $template = [
            "fields" => [
                ["name" => "title", "source" => "title"],
                ["name" => "introduction", "source" => "introduction"],
            ],
            "elements" => [["name" => "card", "type" => "social_card"]],
        ];

        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertStringContainsString(
            "<p>This is the introduction text.</p>",
            $result["html_elements"]["card"],
        );
    }

    /**
     * Unknown element type produces an empty string.
     */
    #[Test]
    public function element_unknown_type_returns_empty_string(): void
    {
        $template = [
            "fields" => [],
            "elements" => [["name" => "mystery", "type" => "unknown_type"]],
        ];

        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame("", $result["html_elements"]["mystery"]);
    }

    /**
     * When template has no "elements" key, html_elements is empty.
     */
    #[Test]
    public function empty_elements_when_template_has_no_elements_key(): void
    {
        $template = ["fields" => []];
        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertSame([], $result["html_elements"]);
    }

    // ─────────────────────────────────────────────────────────────
    // truncate() behaviour (via meta_description)
    // ─────────────────────────────────────────────────────────────

    /**
     * Text exactly at the limit is not truncated.
     */
    #[Test]
    public function truncate_exactly_at_limit_unchanged(): void
    {
        $exact = str_repeat("X", 160);
        $doc = new MarkdownDocument(
            title: "T",
            introduction: $exact,
            sections: [],
            conclusion: "",
            raw: "",
        );

        $template = [
            "fields" => [["name" => "d", "source" => "meta_description"]],
        ];
        $result = $this->renderer->render($doc, $template, []);

        $this->assertSame($exact, $result["raw_json_fields"]["d"]);
    }

    /**
     * Text one character over the limit is truncated with "..." appended.
     */
    #[Test]
    public function truncate_one_over_limit(): void
    {
        $text = str_repeat("Y", 161);
        $doc = new MarkdownDocument(
            title: "T",
            introduction: $text,
            sections: [],
            conclusion: "",
            raw: "",
        );

        $template = [
            "fields" => [["name" => "d", "source" => "meta_description"]],
        ];
        $result = $this->renderer->render($doc, $template, []);

        $this->assertSame(160, mb_strlen($result["raw_json_fields"]["d"]));
        $this->assertStringEndsWith("...", $result["raw_json_fields"]["d"]);
    }

    /**
     * Truncation is multibyte-safe.
     */
    #[Test]
    public function truncate_is_multibyte_safe(): void
    {
        $text = str_repeat("é", 100) . str_repeat("a", 70); // 170 chars total
        $doc = new MarkdownDocument(
            title: "T",
            introduction: $text,
            sections: [],
            conclusion: "",
            raw: "",
        );

        $template = [
            "fields" => [["name" => "d", "source" => "meta_description"]],
        ];
        $result = $this->renderer->render($doc, $template, []);

        $this->assertSame(160, mb_strlen($result["raw_json_fields"]["d"]));
        $this->assertStringEndsWith("...", $result["raw_json_fields"]["d"]);
    }

    // ─────────────────────────────────────────────────────────────
    // Edge cases
    // ─────────────────────────────────────────────────────────────

    /**
     * An empty template produces empty fields and elements.
     */
    #[Test]
    public function empty_template_produces_empty_results(): void
    {
        $result = $this->renderer->render($this->makeDoc(), [], []);

        $this->assertSame([], $result["raw_json_fields"]);
        $this->assertSame([], $result["html_elements"]);
    }

    /**
     * Multiple fields and elements are all rendered correctly.
     */
    #[Test]
    public function multiple_fields_and_elements(): void
    {
        $template = [
            "fields" => [
                ["name" => "title", "source" => "title"],
                ["name" => "body", "source" => "body"],
                ["name" => "conc", "source" => "conclusion"],
            ],
            "elements" => [
                [
                    "name" => "h",
                    "type" => "template",
                    "template" => "<h1>{title}</h1>",
                ],
                ["name" => "m", "type" => "meta_tags", "fields" => ["body"]],
                ["name" => "s", "type" => "social_card"],
            ],
        ];

        $result = $this->renderer->render($this->makeDoc(), $template, []);

        $this->assertCount(3, $result["raw_json_fields"]);
        $this->assertCount(3, $result["html_elements"]);
        $this->assertSame("<h1>Test Title</h1>", $result["html_elements"]["h"]);
        $this->assertStringContainsString(
            "og:title",
            $result["html_elements"]["m"],
        );
        $this->assertStringContainsString(
            "social-card",
            $result["html_elements"]["s"],
        );
    }
}
