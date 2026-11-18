<?php

declare(strict_types=1);

namespace Tests\Unit\PncTemplates\Services;

use App\PncTemplates\Contracts\TemplateTransformerInterface;
use App\PncTemplates\Engine\MarkdownDocument;
use App\PncTemplates\Engine\MarkdownParser;
use App\PncTemplates\Engine\TemplateRenderer;
use App\PncTemplates\Services\TemplateTransformerService;
use App\PncServices\Contracts\ContentPersistenceInterface;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Unit tests for TemplateTransformerService.
 *
 * Verifies the full transformation pipeline:
 *  - Markdown reading and null-guard
 *  - Template resolution cascade (override → app → default → exception)
 *  - Parser and renderer delegation
 *  - Output persistence (JSON + HTML elements)
 *  - Return value structure
 */
final class TemplateTransformerServiceTest extends TestCase
{
    /** @var MockInterface&ContentPersistenceInterface */
    private ContentPersistenceInterface&MockInterface $persistence;

    /** @var MockInterface&MarkdownParser */
    private MarkdownParser&MockInterface $parser;

    /** @var MockInterface&TemplateRenderer */
    private TemplateRenderer&MockInterface $renderer;

    /** @var TemplateTransformerService */
    private TemplateTransformerService $service;

    /** @var string Temp directory used for template override resolution. */
    private string $overrideDir;

    /**
     * Set up mocks, temp template file, and service instance.
     */
    /**
     * Set up mocks, temp template file, and service instance.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->persistence = Mockery::mock(ContentPersistenceInterface::class);
        $this->parser = Mockery::mock(MarkdownParser::class);
        $this->renderer = Mockery::mock(TemplateRenderer::class);

        $this->service = new TemplateTransformerService(
            $this->persistence,
            $this->parser,
            $this->renderer,
        );

        $this->overrideDir =
            sys_get_temp_dir() . "/pnc_test_overrides_" . uniqid();
        mkdir($this->overrideDir, 0755, true);
        file_put_contents(
            $this->overrideDir . "/blog_post.json",
            json_encode(["name" => "blog_post", "elements" => ["body"]]),
        );

        $_ENV["TEMPLATE_OVERRIDES"] = $this->overrideDir;
        $_SERVER["TEMPLATE_OVERRIDES"] = $this->overrideDir;
    }

    /**
     * Clean up temp files and Mockery.
     */
    protected function tearDown(): void
    {
        array_map("unlink", glob($this->overrideDir . "/*") ?: []);
        @rmdir($this->overrideDir);

        unset($_ENV["TEMPLATE_OVERRIDES"], $_SERVER["TEMPLATE_OVERRIDES"]);

        parent::tearDown();
        Mockery::close();
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * A standard trackInfo array matching the documented shape.
     */
    private function trackInfo(): array
    {
        return [
            "domain" => "example.com",
            "type" => "article",
            "template" => "blog_post",
            "track" => "track-1",
            "track_type" => "blog",
            "workflow" => "standard",
            "lang" => "en",
            "content_json" => "/output/track-1/content.json",
            "content_md" => "/output/track-1/content.md",
            "description_md" => "/output/track-1/description.md",
            "research_text" => "research text",
        ];
    }

    /**
     * A sample rendered result from TemplateRenderer.
     */
    private function renderResult(): array
    {
        return [
            "raw_json_fields" => ["title" => "Hello", "body" => "World"],
            "html_elements" => [
                "header" => "<h1>Hello</h1>",
                "body" => "<p>World</p>",
            ],
        ];
    }

    /**
     * Create a mock MarkdownDocument to satisfy the return type.
     */
    private function markdownDocument(): MarkdownDocument
    {
        return Mockery::mock(MarkdownDocument::class);
    }

    /**
     * Wire up the happy-path mock expectations for a full transform call.
     */
    private function mockHappyPath(): void
    {
        $this->persistence
            ->shouldReceive("readText")
            ->once()
            ->with("/output/track-1/content.md")
            ->andReturn('# Hello\n\nWorld');

        $this->persistence
            ->shouldReceive("readJson")
            ->once()
            ->andReturn([
                "name" => "blog_post",
                "elements" => ["header", "body"],
            ]);

        $this->parser
            ->shouldReceive("parse")
            ->once()
            ->andReturn($this->markdownDocument());

        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->andReturn($this->renderResult());

        $this->persistence
            ->shouldReceive("saveJson")
            ->once()
            ->with("/output/track-1/content.json", [
                "title" => "Hello",
                "body" => "World",
            ]);

        $this->persistence
            ->shouldReceive("saveHtml")
            ->once()
            ->with("/output/track-1/element_header.html", "<h1>Hello</h1>");

        $this->persistence
            ->shouldReceive("saveHtml")
            ->once()
            ->with("/output/track-1/element_body.html", "<p>World</p>");
    }

    // ─────────────────────────────────────────────────────────────
    // Happy path
    // ─────────────────────────────────────────────────────────────

    /**
     * A full successful transform reads markdown, loads the template,
     * parses, renders, persists all artifacts, and returns the
     * documented array structure.
     */
    public function test_transform_returns_expected_structure_on_success(): void
    {
        $this->mockHappyPath();

        $result = $this->service->transform("blog_post", $this->trackInfo(), [
            "key" => "value",
        ]);

        $this->assertSame("/output/track-1", $result["target_directory"]);
        $this->assertSame(
            ["title" => "Hello", "body" => "World"],
            $result["extracted_fields"],
        );
        $this->assertSame(
            ["header" => "<h1>Hello</h1>", "body" => "<p>World</p>"],
            $result["html_elements"],
        );
    }

    /**
     * The service implements TemplateTransformerInterface.
     */
    public function test_service_implements_contract(): void
    {
        $this->assertInstanceOf(
            TemplateTransformerInterface::class,
            $this->service,
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Markdown null guard
    // ─────────────────────────────────────────────────────────────

    /**
     * When contentPersistence->readText() returns null, an
     * InvalidArgumentException is thrown with the file path.
     */
    public function test_throws_when_markdown_not_found(): void
    {
        $this->persistence
            ->shouldReceive("readText")
            ->once()
            ->with("/output/track-1/content.md")
            ->andReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Content markdown not found at: /output/track-1/content.md",
        );

        $this->service->transform("blog_post", $this->trackInfo(), []);
    }

    // ─────────────────────────────────────────────────────────────
    // Template resolution
    // ─────────────────────────────────────────────────────────────

    /**
     * When the resolved template path yields null from readJson,
     * an InvalidArgumentException is thrown.
     */
    public function test_throws_when_template_json_is_invalid(): void
    {
        $this->persistence
            ->shouldReceive("readText")
            ->once()
            ->andReturn("# Content");

        $this->persistence->shouldReceive("readJson")->once()->andReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("is not valid JSON");

        $this->service->transform("blog_post", $this->trackInfo(), []);
    }

    /**
     * The override directory is checked first: when TEMPLATE_OVERRIDES
     * is set and contains the file, that path is used for readJson.
     */
    public function test_resolves_template_from_override_directory(): void
    {
        $this->persistence
            ->shouldReceive("readText")
            ->once()
            ->andReturn("# Content");

        $this->persistence
            ->shouldReceive("readJson")
            ->once()
            ->with($this->overrideDir . "/blog_post.json")
            ->andReturn(["name" => "blog_post"]);

        $this->parser
            ->shouldReceive("parse")
            ->once()
            ->andReturn($this->markdownDocument());
        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->andReturn([
                "raw_json_fields" => [],
                "html_elements" => [],
            ]);

        // ← These two lines were missing:
        $this->persistence
            ->shouldReceive("saveJson")
            ->once()
            ->with("/output/track-1/content.json", []);
        $this->persistence->shouldNotReceive("saveHtml");

        $this->service->transform("blog_post", $this->trackInfo(), []);
    }

    // ─────────────────────────────────────────────────────────────
    // Persistence
    // ─────────────────────────────────────────────────────────────

    /**
     * When the renderer returns zero HTML elements, no saveHtml calls
     * are made but saveJson is still called.
     */
    public function test_saves_json_but_no_html_when_no_elements(): void
    {
        $this->persistence
            ->shouldReceive("readText")
            ->once()
            ->andReturn("# Content");
        $this->persistence
            ->shouldReceive("readJson")
            ->once()
            ->andReturn(["name" => "blog_post"]);
        $this->parser
            ->shouldReceive("parse")
            ->once()
            ->andReturn($this->markdownDocument());
        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->andReturn([
                "raw_json_fields" => ["title" => "Only JSON"],
                "html_elements" => [],
            ]);

        $this->persistence
            ->shouldReceive("saveJson")
            ->once()
            ->with("/output/track-1/content.json", ["title" => "Only JSON"]);
        $this->persistence->shouldNotReceive("saveHtml");

        $result = $this->service->transform(
            "blog_post",
            $this->trackInfo(),
            [],
        );

        $this->assertSame([], $result["html_elements"]);
    }

    /**
     * Multiple HTML elements are each saved to their own file.
     */
    public function test_saves_each_html_element_to_separate_file(): void
    {
        $this->persistence
            ->shouldReceive("readText")
            ->once()
            ->andReturn("# Content");
        $this->persistence
            ->shouldReceive("readJson")
            ->once()
            ->andReturn(["name" => "blog_post"]);
        $this->parser
            ->shouldReceive("parse")
            ->once()
            ->andReturn($this->markdownDocument());
        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->andReturn([
                "raw_json_fields" => [],
                "html_elements" => [
                    "header" => "<h1>H</h1>",
                    "body" => "<p>B</p>",
                    "footer" => "<footer>F</footer>",
                ],
            ]);

        $this->persistence->shouldReceive("saveJson")->once();
        $this->persistence
            ->shouldReceive("saveHtml")
            ->once()
            ->with("/output/track-1/element_header.html", "<h1>H</h1>");
        $this->persistence
            ->shouldReceive("saveHtml")
            ->once()
            ->with("/output/track-1/element_body.html", "<p>B</p>");
        $this->persistence
            ->shouldReceive("saveHtml")
            ->once()
            ->with("/output/track-1/element_footer.html", "<footer>F</footer>");

        $result = $this->service->transform(
            "blog_post",
            $this->trackInfo(),
            [],
        );

        $this->assertCount(3, $result["html_elements"]);
    }

    // ─────────────────────────────────────────────────────────────
    // target_directory derivation
    // ─────────────────────────────────────────────────────────────

    /**
     * target_directory is derived from dirname(content_md).
     */
    public function test_target_directory_is_derived_from_content_md_path(): void
    {
        $info = $this->trackInfo();
        $info["content_md"] = "/deep/nested/path/article.md";

        $this->persistence->shouldReceive("readText")->once()->andReturn("# X");
        $this->persistence->shouldReceive("readJson")->once()->andReturn([]);
        $this->parser
            ->shouldReceive("parse")
            ->once()
            ->andReturn($this->markdownDocument());
        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->andReturn([
                "raw_json_fields" => [],
                "html_elements" => [],
            ]);
        $this->persistence
            ->shouldReceive("saveJson")
            ->once()
            ->with("/deep/nested/path/content.json", []);

        $result = $this->service->transform("blog_post", $info, []);

        $this->assertSame("/deep/nested/path", $result["target_directory"]);
    }

    /**
     * When no override or named template exists, resolution falls through
     * to default.json. If readJson returns null for it, an
     * InvalidArgumentException about invalid JSON is thrown.
     */
    public function test_throws_when_default_template_is_invalid_json(): void
    {
        unset($_ENV["TEMPLATE_OVERRIDES"], $_SERVER["TEMPLATE_OVERRIDES"]);

        $this->persistence
            ->shouldReceive("readText")
            ->once()
            ->andReturn("# Content");

        $this->persistence
            ->shouldReceive("readJson")
            ->once()
            ->with(app_path("PncTemplates/Templates/default.json"))
            ->andReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("is not valid JSON");

        $this->service->transform(
            "definitely_not_a_real_template",
            $this->trackInfo(),
            [],
        );
    }
}
