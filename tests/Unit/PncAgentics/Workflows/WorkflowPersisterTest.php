<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Workflows;

use App\PncAgentics\Workflows\TemplateResolver;
use App\PncAgentics\Workflows\WorkflowPersister;
use App\PncServices\Contracts\ContentPersistenceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see WorkflowPersister}.
 *
 * Verifies that persist():
 *  - Resolves path and filename templates from context via TemplateResolver.
 *  - Reads content from the configured "output_key" (or "final_output" fallback).
 *  - JSON-encodes array content before delegating to saveMarkdown().
 *  - Casts non-string scalars (int, bool) to string.
 *  - Defaults to empty string when the content key is missing from context.
 *  - Calls setPath() before saveMarkdown() (ordering guarantee).
 *  - Handles missing template keys gracefully (resolves to "").
 *
 * ContentPersistenceInterface is mocked via Mockery. TemplateResolver is used
 * as a real instance (pure logic, no external dependencies).
 *
 * Note: ContentPersistenceInterface::saveMarkdown() declares a `: string`
 * return type (for future REST API use), so every mock expectation must
 * include ->andReturn("").
 *
 * @covers \App\PncAgentics\Workflows\WorkflowPersister
 */
class WorkflowPersisterTest extends TestCase
{
    /**
     * Mock content persistence service.
     */
    private ContentPersistenceInterface $persistence;

    /**
     * Real template resolver (no mocking needed — pure logic).
     */
    private TemplateResolver $resolver;

    /**
     * The system under test.
     */
    private WorkflowPersister $sut;

    /**
     * Set up the mock persistence service and instantiate the persister.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->persistence = \Mockery::mock(ContentPersistenceInterface::class);
        $this->resolver = new TemplateResolver();
        $this->sut = new WorkflowPersister($this->persistence, $this->resolver);
    }

    /**
     * Close Mockery expectations after each test.
     */
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Normal persistence
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Should resolve path and filename templates from context, read content
     * from the configured output_key, and call setPath() + saveMarkdown()
     * with the resolved values.
     */
    #[Test]
    public function persist_resolvesTemplatesAndSavesContent(): void
    {
        $this->persistence
            ->shouldReceive("setPath")
            ->once()
            ->with("/output/articles");

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("# My Article\n\nContent here.", "my-article.md")
            ->andReturn("");

        $this->sut->persist(
            [
                "path" => "{{domain_dir}}",
                "filename" => "{{title_slug}}.md",
                "output_key" => "body",
            ],
            [
                "domain_dir" => "/output/articles",
                "title_slug" => "my-article",
                "body" => "# My Article\n\nContent here.",
            ],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Output key resolution
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When "output_key" is specified in the persist config, that key should
     * be used to read content from context (not "final_output").
     */
    #[Test]
    public function persist_usesConfiguredOutputKey(): void
    {
        $this->persistence->shouldReceive("setPath")->once()->with("/out");

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("custom content", "file.md")
            ->andReturn("");

        $this->sut->persist(
            [
                "path" => "/out",
                "filename" => "file.md",
                "output_key" => "custom_key",
            ],
            [
                "custom_key" => "custom content",
                "final_output" => "should not use this",
            ],
        );
    }

    /**
     * When "output_key" is NOT specified in the persist config, should fall
     * back to reading content from the "final_output" context key.
     */
    #[Test]
    public function persist_fallsBackToFinalOutputWhenNoOutputKey(): void
    {
        $this->persistence->shouldReceive("setPath")->once()->with("/out");

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("fallback content", "file.md")
            ->andReturn("");

        $this->sut->persist(
            ["path" => "/out", "filename" => "file.md"],
            ["final_output" => "fallback content"],
        );
    }

    /**
     * When the configured content key is missing from context, should save
     * an empty string (not crash or save null).
     */
    #[Test]
    public function persist_savesEmptyStringWhenContentKeyMissing(): void
    {
        $this->persistence->shouldReceive("setPath")->once()->with("/out");

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("", "file.md")
            ->andReturn("");

        $this->sut->persist(
            [
                "path" => "/out",
                "filename" => "file.md",
                "output_key" => "nonexistent",
            ],
            ["other_key" => "some value"],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Content type handling
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the content value is an array, it should be JSON-encoded into a
     * compact string before being passed to saveMarkdown().
     */
    #[Test]
    public function persist_jsonEncodesArrayContent(): void
    {
        $this->persistence->shouldReceive("setPath")->once()->with("/out");

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with('{"key":"value","list":["a","b"]}', "data.md")
            ->andReturn("");

        $this->sut->persist(
            ["path" => "/out", "filename" => "data.md", "output_key" => "data"],
            ["data" => ["key" => "value", "list" => ["a", "b"]]],
        );
    }

    /**
     * When the content value is an integer, it should be cast to its string
     * representation before being passed to saveMarkdown().
     */
    #[Test]
    public function persist_castsIntegerContentToString(): void
    {
        $this->persistence->shouldReceive("setPath")->once()->with("/out");

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("42", "number.md")
            ->andReturn("");

        $this->sut->persist(
            [
                "path" => "/out",
                "filename" => "number.md",
                "output_key" => "num",
            ],
            ["num" => 42],
        );
    }

    /**
     * When the content value is a boolean, it should be cast to "1" or ""
     * (PHP's string cast of true/false) before being passed to saveMarkdown().
     */
    #[Test]
    public function persist_castsBooleanContentToString(): void
    {
        $this->persistence->shouldReceive("setPath")->once()->with("/out");

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("1", "flag.md")
            ->andReturn("");

        $this->sut->persist(
            [
                "path" => "/out",
                "filename" => "flag.md",
                "output_key" => "flag",
            ],
            ["flag" => true],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Call ordering
    // ─────────────────────────────────────────────────────────────────────

    /**
     * setPath() must be called before saveMarkdown() to ensure the persistence
     * service knows the target directory when the file is written.
     */
    #[Test]
    public function persist_callsSetPathBeforeSaveMarkdown(): void
    {
        $callOrder = [];

        $this->persistence
            ->shouldReceive("setPath")
            ->once()
            ->andReturnUsing(function () use (&$callOrder) {
                $callOrder[] = "setPath";
            });

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->andReturnUsing(function () use (&$callOrder) {
                $callOrder[] = "saveMarkdown";
                return "";
            });

        $this->sut->persist(
            ["path" => "/out", "filename" => "file.md"],
            ["final_output" => "content"],
        );

        $this->assertSame(["setPath", "saveMarkdown"], $callOrder);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Template resolution in path/filename
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Path and filename templates should support multiple {{key}} placeholders,
     * all resolved from the same context array.
     */
    #[Test]
    public function persist_resolvesMultiplePlaceholdersInPathAndFilename(): void
    {
        $this->persistence
            ->shouldReceive("setPath")
            ->once()
            ->with("/agents/abc-123/2026_11_22");

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("content", "abc-123_article.md")
            ->andReturn("");

        $this->sut->persist(
            [
                "path" => "/agents/{{uuid}}/{{date}}",
                "filename" => "{{uuid}}_{{type}}.md",
            ],
            [
                "uuid" => "abc-123",
                "date" => "2026_11_22",
                "type" => "article",
                "final_output" => "content",
            ],
        );
    }

    /**
     * When a template placeholder references a key that doesn't exist in
     * context, it should resolve to an empty string (not crash or throw).
     */
    #[Test]
    public function persist_handlesMissingTemplateKeysGracefully(): void
    {
        $this->persistence->shouldReceive("setPath")->once()->with("/out/");

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("content", ".md")
            ->andReturn("");

        $this->sut->persist(
            [
                "path" => "/out/{{missing_dir}}",
                "filename" => "{{missing_slug}}.md",
            ],
            ["final_output" => "content"],
        );
    }
}
