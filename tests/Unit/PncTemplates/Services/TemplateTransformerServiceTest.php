<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see TemplateTransformerService}.
 *
 * Verifies the full transformation pipeline:
 *  - Content is passed in-memory (no disk read via persistence).
 *  - "text" key → parser is called, MarkdownDocument is produced.
 *  - Structured content (no "text" key) → MarkdownDocument is constructed
 *    from title/introduction/sections/conclusion keys (parser NOT called).
 *  - Template resolution cascade (override → app → default → exception).
 *  - Renderer receives a MarkdownDocument (never a raw array).
 *  - Output persistence (JSON + HTML elements) via ContentPersistenceInterface.
 *  - target_directory is derived from dirname(content_path_markdown).
 *  - Return value structure.
 *
 * Template files are real files on disk (File::get() is used, not a mock).
 * Config keys:
 *  - pnc.templates.override_dir  (relative path, resolved via base_path())
 *  - pnc.templates.templates_dir (absolute path)
 *
 * @covers \App\PncTemplates\Services\TemplateTransformerService
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

    /** @var string Relative path for override dir (goes into config). */
    private string $overrideRelative;

    /** @var string Absolute path for override dir (where files are created). */
    private string $overrideAbsolute;

    /** @var string Absolute path for the app-level templates dir. */
    private string $templatesDir;

    /**
     * Set up mocks, temp template files, config, and service instance.
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

        // Override dir: relative path in config, absolute on disk.
        $this->overrideRelative = "test_template_overrides_" . uniqid();
        $this->overrideAbsolute = base_path($this->overrideRelative);
        mkdir($this->overrideAbsolute, 0755, true);

        // App-level templates dir: absolute path.
        $this->templatesDir = sys_get_temp_dir() . "/pnc_templates_" . uniqid();
        mkdir($this->templatesDir, 0755, true);

        // Create a default template in the app-level dir.
        file_put_contents(
            $this->templatesDir . "/default.json",
            json_encode([
                "name" => "default",
                "fields" => [],
                "elements" => [],
            ]),
        );

        config()->set("pnc.templates.override_dir", null);
        config()->set("pnc.templates.templates_dir", $this->templatesDir);
    }

    /**
     * Clean up temp files, reset config, close Mockery.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->overrideAbsolute);
        $this->removeDirectory($this->templatesDir);

        config()->set("pnc.templates.override_dir", null);
        config()->set("pnc.templates.templates_dir", null);

        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * A standard trackInfo array matching the production shape.
     */
    private function trackInfo(): array
    {
        return [
            "domain" => "example.com",
            "type" => "article",
            "template" => "blog_post",
            "track" => "blog_post",
            "track_type" => "blog",
            "workflow" => "blog_post",
            "lang" => "en",
            "context" => "This for marketing",
            "content_path_json" => "/output/examplecom/article-en/content.json",
            "content_path_markdown" =>
                "/output/examplecom/article-en/content.md",
            "content_description" =>
                "/output/examplecom/article-en/description.md",
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
     * A mock MarkdownDocument instance.
     */
    private function markdownDocument(): MarkdownDocument
    {
        return Mockery::mock(MarkdownDocument::class);
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($dir);
    }

    // ─────────────────────────────────────────────────────────────
    // Happy path — "text" key (markdown parsing)
    // ─────────────────────────────────────────────────────────────

    /**
     * When contentData contains a "text" key with a string value,
     * the parser IS called and its MarkdownDocument result is passed
     * to the renderer.
     */
    #[Test]
    public function transform_with_text_key_calls_parser(): void
    {
        $contentData = ["text" => "# Hello\n\nWorld"];
        $doc = $this->markdownDocument();

        $this->parser
            ->shouldReceive("parse")
            ->once()
            ->with("# Hello\n\nWorld")
            ->andReturn($doc);

        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->withArgs(function ($parsed, array $template, array $data) use (
                $doc,
            ): bool {
                return $parsed === $doc && $data["text"] === "# Hello\n\nWorld";
            })
            ->andReturn($this->renderResult());

        $this->persistence
            ->shouldReceive("saveJson")
            ->once()
            ->with("/output/examplecom/article-en/content.json", [
                "title" => "Hello",
                "body" => "World",
            ]);

        $this->persistence->shouldReceive("saveHtml")->twice();

        $result = $this->service->transform(
            "default",
            $this->trackInfo(),
            $contentData,
        );

        $this->assertSame(
            "/output/examplecom/article-en",
            $result["target_directory"],
        );
        $this->assertSame(
            ["title" => "Hello", "body" => "World"],
            $result["extracted_fields"],
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Happy path — structured content (no "text" key)
    // ─────────────────────────────────────────────────────────────

    /**
     * When contentData is a structured array (no "text" key), the parser
     * is NOT called. A MarkdownDocument is constructed from the structured
     * keys and passed to the renderer.
     */
    #[Test]
    public function transform_with_structured_content_skips_parser(): void
    {
        $contentData = [
            "title" => "Hello",
            "introduction" => "Intro text",
            "sections" => ["Section A" => "Content A"],
            "conclusion" => "Conclusion text",
        ];

        // Parser should NOT be called for structured content.
        $this->parser->shouldNotReceive("parse");

        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->withArgs(function ($doc, array $template, array $data): bool {
                // $doc must be a MarkdownDocument (not an array)
                return $doc instanceof MarkdownDocument &&
                    $data["title"] === "Hello" &&
                    $data["conclusion"] === "Conclusion text";
            })
            ->andReturn($this->renderResult());

        $this->persistence
            ->shouldReceive("saveJson")
            ->once()
            ->with("/output/examplecom/article-en/content.json", [
                "title" => "Hello",
                "body" => "World",
            ]);

        $this->persistence->shouldReceive("saveHtml")->twice();

        $result = $this->service->transform(
            "default",
            $this->trackInfo(),
            $contentData,
        );

        $this->assertSame(
            "/output/examplecom/article-en",
            $result["target_directory"],
        );
    }

    /**
     * When contentData is an empty array, a MarkdownDocument with empty
     * fields is constructed and passed to the renderer.
     */
    #[Test]
    public function transform_with_empty_content_data(): void
    {
        $this->parser->shouldNotReceive("parse");

        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->withArgs(function ($doc, array $template, array $data): bool {
                return $doc instanceof MarkdownDocument && $data === [];
            })
            ->andReturn([
                "raw_json_fields" => [],
                "html_elements" => [],
            ]);

        $this->persistence
            ->shouldReceive("saveJson")
            ->once()
            ->with("/output/examplecom/article-en/content.json", []);

        $this->service->transform("default", $this->trackInfo(), []);
    }

    // ─────────────────────────────────────────────────────────────
    // Contract
    // ─────────────────────────────────────────────────────────────

    /**
     * The service implements TemplateTransformerInterface.
     */
    #[Test]
    public function service_implements_contract(): void
    {
        $this->assertInstanceOf(
            TemplateTransformerInterface::class,
            $this->service,
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Template resolution
    // ─────────────────────────────────────────────────────────────

    /**
     * When the override dir is set (relative) and contains the file,
     * it is preferred over the app-level templates dir.
     */
    #[Test]
    public function transform_resolves_template_from_override_directory(): void
    {
        file_put_contents(
            $this->overrideAbsolute . "/my_template.json",
            json_encode([
                "name" => "my_template",
                "source" => "override",
                "fields" => [],
                "elements" => [],
            ]),
        );

        file_put_contents(
            $this->templatesDir . "/my_template.json",
            json_encode([
                "name" => "my_template",
                "source" => "app",
                "fields" => [],
                "elements" => [],
            ]),
        );

        config()->set("pnc.templates.override_dir", $this->overrideRelative);

        $this->parser->shouldNotReceive("parse");

        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->withArgs(function ($doc, array $template, array $data): bool {
                return $template["source"] === "override";
            })
            ->andReturn(["raw_json_fields" => [], "html_elements" => []]);

        $this->persistence
            ->shouldReceive("saveJson")
            ->once()
            ->with("/output/examplecom/article-en/content.json", []);

        $this->service->transform("my_template", $this->trackInfo(), []);
    }

    /**
     * When the override dir is set but does NOT contain the file,
     * resolution falls through to the app-level templates dir.
     */
    #[Test]
    public function transform_falls_through_to_app_templates_when_override_missing(): void
    {
        config()->set("pnc.templates.override_dir", $this->overrideRelative);

        file_put_contents(
            $this->templatesDir . "/my_template.json",
            json_encode([
                "name" => "my_template",
                "source" => "app",
                "fields" => [],
                "elements" => [],
            ]),
        );

        $this->parser->shouldNotReceive("parse");

        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->withArgs(function ($doc, array $template, array $data): bool {
                return $template["source"] === "app";
            })
            ->andReturn(["raw_json_fields" => [], "html_elements" => []]);

        $this->persistence
            ->shouldReceive("saveJson")
            ->once()
            ->with("/output/examplecom/article-en/content.json", []);

        $this->service->transform("my_template", $this->trackInfo(), []);
    }

    /**
     * When neither the override nor the named file exists, resolution
     * falls back to default.json in the app-level dir.
     */
    #[Test]
    public function transform_falls_back_to_default_json(): void
    {
        // No override, no named file. default.json exists from setUp().

        $this->parser->shouldNotReceive("parse");

        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->withArgs(function ($doc, array $template, array $data): bool {
                return $template["name"] === "default";
            })
            ->andReturn(["raw_json_fields" => [], "html_elements" => []]);

        $this->persistence
            ->shouldReceive("saveJson")
            ->once()
            ->with("/output/examplecom/article-en/content.json", []);

        $this->service->transform(
            "nonexistent_template",
            $this->trackInfo(),
            [],
        );
    }

    /**
     * When no template is found anywhere, an InvalidArgumentException is thrown.
     */
    #[Test]
    public function transform_throws_when_no_template_found(): void
    {
        unlink($this->templatesDir . "/default.json");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Could not resolve template");

        $this->service->transform("nonexistent", $this->trackInfo(), []);
    }

    /**
     * When the template file contains invalid JSON, an InvalidArgumentException
     * is thrown with the JSON error message.
     */
    #[Test]
    public function transform_throws_on_invalid_template_json(): void
    {
        file_put_contents(
            $this->templatesDir . "/broken.json",
            "{invalid json here",
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("is not valid JSON");

        $this->service->transform("broken", $this->trackInfo(), []);
    }

    // ─────────────────────────────────────────────────────────────
    // Persistence
    // ─────────────────────────────────────────────────────────────

    /**
     * When the renderer returns zero HTML elements, no saveHtml calls
     * are made but saveJson is still called.
     */
    #[Test]
    public function transform_saves_json_but_no_html_when_no_elements(): void
    {
        $this->parser->shouldNotReceive("parse");

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
            ->with("/output/examplecom/article-en/content.json", [
                "title" => "Only JSON",
            ]);

        $this->persistence->shouldNotReceive("saveHtml");

        $result = $this->service->transform("default", $this->trackInfo(), []);

        $this->assertSame([], $result["html_elements"]);
    }

    /**
     * Multiple HTML elements are each saved to their own file.
     */
    #[Test]
    public function transform_saves_each_html_element_to_separate_file(): void
    {
        $this->parser->shouldNotReceive("parse");

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
            ->with(
                "/output/examplecom/article-en/element_header.html",
                "<h1>H</h1>",
            );

        $this->persistence
            ->shouldReceive("saveHtml")
            ->once()
            ->with(
                "/output/examplecom/article-en/element_body.html",
                "<p>B</p>",
            );

        $this->persistence
            ->shouldReceive("saveHtml")
            ->once()
            ->with(
                "/output/examplecom/article-en/element_footer.html",
                "<footer>F</footer>",
            );

        $result = $this->service->transform("default", $this->trackInfo(), []);

        $this->assertCount(3, $result["html_elements"]);
    }

    // ─────────────────────────────────────────────────────────────
    // target_directory derivation
    // ─────────────────────────────────────────────────────────────

    /**
     * target_directory is derived from dirname(content_path_markdown).
     */
    #[Test]
    public function transform_target_directory_is_derived_from_content_path_markdown(): void
    {
        $info = $this->trackInfo();
        $info["content_path_markdown"] = "/deep/nested/path/article.md";

        $this->parser->shouldNotReceive("parse");

        $this->renderer
            ->shouldReceive("render")
            ->once()
            ->andReturn(["raw_json_fields" => [], "html_elements" => []]);

        $this->persistence
            ->shouldReceive("saveJson")
            ->once()
            ->with("/deep/nested/path/content.json", []);

        $result = $this->service->transform("default", $info, []);

        $this->assertSame("/deep/nested/path", $result["target_directory"]);
    }
}
