<?php

declare(strict_types=1);

namespace Tests\Unit\PncServices\Agent\Actions;

use App\PncServices\Agent\Actions\TemplateAgentContentAction;
use App\PncServices\Contracts\ContentPersistenceInterface;
use App\PncTemplates\Contracts\TemplateTransformerInterface;
use Illuminate\Support\Facades\Concurrency;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see TemplateAgentContentAction}.
 *
 * Verifies that execute():
 *  - Builds one task per track from the structure array.
 *  - Delegates to ContentPersistenceInterface::readJson() and
 *    TemplateTransformerInterface::transform() for each track.
 *  - Runs tasks in parallel via Concurrency when the driver is "fork".
 *  - Falls back to sequential execution when Concurrency fails
 *    or the default driver is "sync".
 *  - Catches per-track exceptions in fallback mode and returns
 *    error messages instead of propagating.
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
     * Mock for content persistence.
     */
    private Mockery\MockInterface $contentPersistence;

    /**
     * Sample structure with two tracks.
     */
    private array $structure = [
        "tracks" => [
            "track1" => [
                "template" => "blog_post",
                "content_json" => "/data/track1/content.json",
                "content_md" => "/data/track1/content.md",
                "domain" => "blog.com",
                "type" => "social",
                "track" => "social_media",
                "track_type" => "social",
                "workflow" => "default",
                "lang" => "en",
                "description_md" => "/data/track1/desc.md",
                "research_text" => "research",
            ],
            "track2" => [
                "template" => "social",
                "content_json" => "/data/track2/content.json",
                "content_md" => "/data/track2/content.md",
                "domain" => "meeting.com",
                "type" => "social",
                "track" => "social_media",
                "track_type" => "social",
                "workflow" => "default",
                "lang" => "en",
                "description_md" => "/data/track2/desc.md",
                "research_text" => "research2",
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
            "pncservices.ai.timeout" => 600,
            "concurrency.default" => "fork",
        ]);

        $this->transformer = Mockery::mock(TemplateTransformerInterface::class);
        $this->contentPersistence = Mockery::mock(
            ContentPersistenceInterface::class,
        );

        $this->action = new TemplateAgentContentAction(
            $this->transformer,
            $this->contentPersistence,
        );
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
            "track1" => "Success: [track1] saved at [/data/track1].",
            "track2" => "Success: [track2] saved at [/data/track2].",
        ];

        $driver = Mockery::mock();
        $driver
            ->shouldReceive("run")
            ->once()
            ->withArgs(function ($tasks, $timeout): bool {
                return is_array($tasks) &&
                    count($tasks) === 2 &&
                    array_key_exists("track1", $tasks) &&
                    array_key_exists("track2", $tasks) &&
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

        $this->contentPersistence
            ->shouldReceive("readJson")
            ->once()
            ->with("/data/track1/content.json")
            ->andReturn(["field" => "value1"]);
        $this->contentPersistence
            ->shouldReceive("readJson")
            ->once()
            ->with("/data/track2/content.json")
            ->andReturn(["field" => "value2"]);

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->with("blog_post", Mockery::type("array"), ["field" => "value1"])
            ->andReturn([
                "target_directory" => "/data/track1",
                "extracted_fields" => ["field" => "value1"],
                "html_elements" => [],
            ]);
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->with("social", Mockery::type("array"), ["field" => "value2"])
            ->andReturn([
                "target_directory" => "/data/track2",
                "extracted_fields" => ["field" => "value2"],
                "html_elements" => [],
            ]);

        $result = $this->action->execute($this->structure);

        $this->assertCount(2, $result);
        $this->assertStringContainsString("track1", $result["track1"]);
        $this->assertStringContainsString("track2", $result["track2"]);
        $this->assertStringContainsString("Success", $result["track1"]);
        $this->assertStringContainsString("Success", $result["track2"]);
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

        $this->contentPersistence
            ->shouldReceive("readJson")
            ->once()
            ->with("/data/track1/content.json")
            ->andReturn(["a" => 1]);
        $this->contentPersistence
            ->shouldReceive("readJson")
            ->once()
            ->with("/data/track2/content.json")
            ->andReturn(["b" => 2]);

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andReturn([
                "target_directory" => "/out/1",
                "extracted_fields" => [],
                "html_elements" => [],
            ]);
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andReturn([
                "target_directory" => "/out/2",
                "extracted_fields" => [],
                "html_elements" => [],
            ]);

        $result = $this->action->execute($this->structure);

        $this->assertCount(2, $result);
        $this->assertStringContainsString("Success", $result["track1"]);
        $this->assertStringContainsString("Success", $result["track2"]);
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
        $this->contentPersistence
            ->shouldReceive("readJson")
            ->once()
            ->with("/data/track1/content.json")
            ->andReturn(["ok" => true]);
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andReturn([
                "target_directory" => "/data/track1",
                "extracted_fields" => [],
                "html_elements" => [],
            ]);

        // track2 fails
        $this->contentPersistence
            ->shouldReceive("readJson")
            ->once()
            ->with("/data/track2/content.json")
            ->andReturn(null);
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andThrow(
                new \InvalidArgumentException("Content markdown not found"),
            );

        $result = $this->action->execute($this->structure);

        $this->assertStringContainsString("Success", $result["track1"]);
        $this->assertStringContainsString("Error", $result["track2"]);
        $this->assertStringContainsString("track2", $result["track2"]);
        $this->assertStringContainsString(
            "Content markdown not found",
            $result["track2"],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Data loading
    // ─────────────────────────────────────────────────────────────────────

    /**
     * execute() should read content JSON via ContentPersistenceInterface
     * and pass the decoded data to the transformer.
     */
    #[Test]
    public function execute_reads_content_json_and_passes_to_transformer(): void
    {
        config(["concurrency.default" => "sync"]);

        $contentData = ["title" => "Test Post", "body" => "Hello"];

        $this->contentPersistence
            ->shouldReceive("readJson")
            ->once()
            ->with("/data/track1/content.json")
            ->andReturn($contentData);

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->with("blog_post", Mockery::type("array"), $contentData)
            ->andReturn([
                "target_directory" => "/data/track1",
                "extracted_fields" => $contentData,
                "html_elements" => [],
            ]);

        $this->action->execute($this->structure);

        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Timeout config
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The timeout passed to Concurrency should be read from the
     * pncservices.ai.timeout config key.
     */
    #[Test]
    public function execute_uses_configured_timeout_for_concurrency(): void
    {
        config(["pncservices.ai.timeout" => 120]);

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

        $this->assertTrue(true);
    }
}
