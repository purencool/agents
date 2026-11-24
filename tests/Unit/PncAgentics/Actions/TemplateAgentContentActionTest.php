<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Actions;

use App\PncAgentics\Actions\TemplateAgentContentAction;
use App\PncTemplates\Contracts\TemplateTransformerInterface;
use Illuminate\Support\Facades\Concurrency;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see TemplateAgentContentAction}.
 *
 * Verifies that execute():
 *  - Builds one task per track from the structure's "tracks" array.
 *  - Delegates to TemplateTransformerInterface::transform() for each track,
 *    passing (template, trackInfo, workflow_results).
 *  - Runs tasks in parallel via Concurrency::driver("fork")->run() when
 *    the default driver is not "sync".
 *  - Falls back to sequential execution when the driver is "sync"
 *    or when Concurrency throws.
 *  - Catches per-track exceptions in fallback mode and returns
 *    error messages instead of propagating.
 *  - The timeout is read from config("pnc.ai.timeout", 600).
 *
 * @covers \App\PncAgentics\Actions\TemplateAgentContentAction
 */
class TemplateAgentContentActionTest extends TestCase
{
    /**
     * The action under test.
     */
    private TemplateAgentContentAction $action;

    /**
     * Mock for the template transformer.
     */
    private Mockery\MockInterface $transformer;

    /**
     * Sample structure with two tracks and workflow_results.
     *
     * @var array{
     *     uuid: string,
     *     title: string,
     *     title_slug: string,
     *     domain_dir: string,
     *     assets_dir: string,
     *     research_dir: string,
     *     workflow_results: array,
     *     tracks: array<string, array>
     * }
     */
    private array $structure = [
        "uuid" => "abc-123-def",
        "title" => "Can AI models be deployed on-premises?",
        "title_slug" => "can_ai_models_be_deployed_on_premises",
        "domain_dir" => "/tmp/agents/abc-123-def",
        "assets_dir" => "/tmp/agents/abc-123-def/assets",
        "research_dir" => "/tmp/agents/abc-123-def/research",
        "workflow_results" => [
            "blog.com" => ["blog-en" => ["text" => "Generated blog content"]],
            "meeting.com" => [
                "meeting-en" => ["text" => "Generated meeting content"],
            ],
        ],
        "tracks" => [
            "blog.com/blog-en" => [
                "domain" => "blog.com",
                "type" => "blog",
                "template" => "blog_post",
                "track" => "blog_post",
                "track_type" => "blog",
                "workflow" => "blog_post",
                "lang" => "en",
                "context" => "This for marketing",
                "content_path_json" =>
                    "/tmp/agents/abc-123-def/blogcom/blog-en/content.json",
                "content_path_markdown" =>
                    "/tmp/agents/abc-123-def/blogcom/blog-en/content.md",
                "content_description" =>
                    "/tmp/agents/abc-123-def/blogcom/blog-en/desc.md",
            ],
            "meeting.com/meeting-en" => [
                "domain" => "meeting.com",
                "type" => "meeting",
                "template" => "social_media",
                "track" => "social_media",
                "track_type" => "social",
                "workflow" => "social_media",
                "lang" => "en",
                "context" => "This for security",
                "content_path_json" =>
                    "/tmp/agents/abc-123-def/meetingcom/meeting-en/content.json",
                "content_path_markdown" =>
                    "/tmp/agents/abc-123-def/meetingcom/meeting-en/content.md",
                "content_description" =>
                    "/tmp/agents/abc-123-def/meetingcom/meeting-en/desc.md",
            ],
        ],
    ];

    /**
     * Set up mocks and the action under test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            "pnc.ai.timeout" => 600,
            "concurrency.default" => "fork",
        ]);

        $this->transformer = Mockery::mock(TemplateTransformerInterface::class);

        $this->action = new TemplateAgentContentAction($this->transformer);
    }

    /**
     * Clean up Mockery after each test.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Parallel execution (Concurrency success)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * execute() should delegate all tasks to Concurrency::driver("fork")->run()
     * and return the results as-is when the parallel run succeeds.
     */
    #[Test]
    public function execute_runs_tasks_in_parallel_via_concurrency(): void
    {
        $expectedResults = [
            "blog.com/blog-en" =>
                "Success: [blog.com/blog-en] saved at [/tmp/agents/abc-123-def/blogcom/blog-en].",
            "meeting.com/meeting-en" =>
                "Success: [meeting.com/meeting-en] saved at [/tmp/agents/abc-123-def/meetingcom/meeting-en].",
        ];

        $driver = Mockery::mock();
        $driver
            ->shouldReceive("run")
            ->once()
            ->withArgs(function ($tasks, $timeout): bool {
                return is_array($tasks) &&
                    count($tasks) === 2 &&
                    array_key_exists("blog.com/blog-en", $tasks) &&
                    array_key_exists("meeting.com/meeting-en", $tasks) &&
                    $timeout === 600;
            })
            ->andReturn($expectedResults);

        Concurrency::shouldReceive("driver")
            ->once()
            ->with("fork")
            ->andReturn($driver);

        $result = $this->action->execute($this->structure);

        $this->assertSame($expectedResults, $result);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fallback: sync driver
    // ─────────────────────────────────────────────────────────────────────

    /**
     * execute() should fall back to sequential execution when the
     * concurrency default driver is "sync".
     */
    #[Test]
    public function execute_falls_back_to_sequential_when_sync(): void
    {
        config(["concurrency.default" => "sync"]);

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->withArgs(function (
                string $template,
                array $trackInfo,
                array $contentData,
            ): bool {
                return $template === "blog_post" &&
                    $trackInfo["domain"] === "blog.com" &&
                    $trackInfo["type"] === "blog" &&
                    isset($contentData["blog.com"]);
            })
            ->andReturn([
                "target_directory" => "/tmp/agents/abc-123-def/blogcom/blog-en",
            ]);

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->withArgs(function (
                string $template,
                array $trackInfo,
                array $contentData,
            ): bool {
                return $template === "social_media" &&
                    $trackInfo["domain"] === "meeting.com" &&
                    $trackInfo["type"] === "meeting" &&
                    isset($contentData["meeting.com"]);
            })
            ->andReturn([
                "target_directory" =>
                    "/tmp/agents/abc-123-def/meetingcom/meeting-en",
            ]);

        $result = $this->action->execute($this->structure);

        $this->assertCount(2, $result);
        $this->assertStringContainsString(
            "Success",
            $result["blog.com/blog-en"],
        );
        $this->assertStringContainsString(
            "Success",
            $result["meeting.com/meeting-en"],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fallback: Concurrency throws
    // ─────────────────────────────────────────────────────────────────────

    /**
     * execute() should catch a Concurrency failure and fall back to
     * sequential execution of all tasks.
     */
    #[Test]
    public function execute_falls_back_when_concurrency_throws(): void
    {
        $driver = Mockery::mock();
        $driver
            ->shouldReceive("run")
            ->once()
            ->andThrow(new \RuntimeException("Fork failed"));

        Concurrency::shouldReceive("driver")
            ->once()
            ->with("fork")
            ->andReturn($driver);

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andReturn([
                "target_directory" => "/out/1",
            ]);
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andReturn([
                "target_directory" => "/out/2",
            ]);

        $result = $this->action->execute($this->structure);

        $this->assertCount(2, $result);
        $this->assertStringContainsString(
            "Success",
            $result["blog.com/blog-en"],
        );
        $this->assertStringContainsString(
            "Success",
            $result["meeting.com/meeting-en"],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fallback: individual task error
    // ─────────────────────────────────────────────────────────────────────

    /**
     * In fallback mode, a per-track exception should be caught and
     * returned as an error message without affecting other tracks.
     */
    #[Test]
    public function execute_catches_per_track_errors_in_fallback(): void
    {
        config(["concurrency.default" => "sync"]);

        // track1 succeeds
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andReturn([
                "target_directory" => "/data/track1",
            ]);

        // track2 fails
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andThrow(
                new \InvalidArgumentException("Content markdown not found"),
            );

        $result = $this->action->execute($this->structure);

        $this->assertStringContainsString(
            "Success",
            $result["blog.com/blog-en"],
        );
        $this->assertStringContainsString(
            "Error",
            $result["meeting.com/meeting-en"],
        );
        $this->assertStringContainsString(
            "meeting.com/meeting-en",
            $result["meeting.com/meeting-en"],
        );
        $this->assertStringContainsString(
            "Content markdown not found",
            $result["meeting.com/meeting-en"],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Transformer arguments
    // ─────────────────────────────────────────────────────────────────────

    /**
     * transform() should receive (template, trackInfo, workflow_results)
     * where workflow_results is the full $structure["workflow_results"] array.
     */
    #[Test]
    public function execute_passes_workflow_results_to_transformer(): void
    {
        config(["concurrency.default" => "sync"]);

        $capturedContentData = null;

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->withArgs(function (
                string $template,
                array $trackInfo,
                array $contentData,
            ) use (&$capturedContentData): bool {
                $capturedContentData = $contentData;
                return true;
            })
            ->andReturn(["target_directory" => "/out"]);

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andReturn(["target_directory" => "/out2"]);

        $this->action->execute($this->structure);

        $this->assertSame(
            $this->structure["workflow_results"],
            $capturedContentData,
        );
    }

    /**
     * When "workflow_results" is absent from the structure, transform()
     * should receive an empty array as the third argument.
     */
    #[Test]
    public function execute_passes_empty_array_when_no_workflow_results(): void
    {
        config(["concurrency.default" => "sync"]);

        $structure = $this->structure;
        unset($structure["workflow_results"]);

        $capturedContentData = null;

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->withArgs(function (
                string $template,
                array $trackInfo,
                array $contentData,
            ) use (&$capturedContentData): bool {
                $capturedContentData = $contentData;
                return true;
            })
            ->andReturn(["target_directory" => "/out"]);

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andReturn(["target_directory" => "/out2"]);

        $this->action->execute($structure);

        $this->assertSame([], $capturedContentData);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Timeout config
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The timeout passed to Concurrency should be read from the
     * pnc.ai.timeout config key.
     */
    #[Test]
    public function execute_uses_configured_timeout_for_concurrency(): void
    {
        config(["pnc.ai.timeout" => 120]);

        $driver = Mockery::mock();
        $driver
            ->shouldReceive("run")
            ->once()
            ->withArgs(function ($tasks, $timeout): bool {
                return $timeout === 120;
            })
            ->andReturn([]);

        Concurrency::shouldReceive("driver")
            ->once()
            ->with("fork")
            ->andReturn($driver);

        $this->action->execute($this->structure);
    }

    /**
     * When pnc.ai.timeout is not configured, the default of 600 should be used.
     */
    #[Test]
    public function execute_uses_default_timeout_when_not_configured(): void
    {
        // Remove the key entirely so config() falls back to the default.
        $config = config("pnc");
        unset($config["ai"]["timeout"]);
        config()->set("pnc", $config);

        $driver = Mockery::mock();
        $driver
            ->shouldReceive("run")
            ->once()
            ->withArgs(function ($tasks, $timeout): bool {
                return $timeout === 600;
            })
            ->andReturn([]);

        Concurrency::shouldReceive("driver")
            ->once()
            ->with("fork")
            ->andReturn($driver);

        $this->action->execute($this->structure);
    }
}
