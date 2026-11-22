<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Actions;

use App\PncAgentics\Actions\WorkFlowAgentContentAction;
use App\PncAgentics\Workflows\JsonWorkflowExecutor;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see WorkFlowAgentContentAction}.
 *
 * Verifies that:
 *  - The correct workflow name is resolved from the first track's "workflow" field.
 *  - The context is built from the structure and passed to the executor.
 *  - The executor's string output is stored in the structure's "content" key.
 *  - All original structure keys are preserved.
 *
 * @covers \App\PncAgentics\Actions\WorkFlowAgentContentAction
 */
class WorkFlowAgentContentActionTest extends TestCase
{
    private JsonWorkflowExecutor $executor;
    private WorkFlowAgentContentAction $action;

    /**
     * A representative content structure with two tracks.
     *
     * @var array<string, mixed>
     */
    private array $structure;

    protected function setUp(): void
    {
        $this->executor = Mockery::mock(JsonWorkflowExecutor::class);
        $this->action = new WorkFlowAgentContentAction($this->executor);

        $this->structure = [
            "uuid" => "abc-123-def",
            "domain_dir" => "/tmp/agents/abc-123-def/2026_11_10_120000",
            "assets_dir" => "/tmp/agents/abc-123-def/2026_11_10_120000/assets",
            "research_dir" => "/tmp/research/abc-123-def/2026_11_10_120000",
            "title" => "Can AI models be deployed on-premises?",
            "title_slug" => "can-ai-models-be-deployed-on-premises",
            "tracks" => [
                "blog.com/blog/en" => [
                    "domain" => "blog.com",
                    "type" => "blog",
                    "workflow" => "default",
                    "lang" => "en",
                    "research_text" => "Research about AI deployment.",
                ],
                "meeting.com/meeting/en" => [
                    "domain" => "meeting.com",
                    "type" => "meeting",
                    "workflow" => "default",
                    "lang" => "en",
                    "research_text" => "Research about AI in meetings.",
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Workflow resolution
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A "default" workflow field should resolve to "universal_documentation".
     */
    #[Test]
    public function execute_resolves_default_workflow_to_universal_documentation(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("universal_documentation", Mockery::type("array"))
            ->andReturn("Generated output");

        $result = $this->action->execute($this->structure);

        $this->assertSame("Generated output", $result["content"]);
    }

    /**
     * A "social_media" workflow field should resolve to "social_media".
     */
    #[Test]
    public function execute_resolves_social_media_workflow(): void
    {
        $structure = $this->structure;
        $structure["tracks"]["blog.com/blog/en"]["workflow"] = "social_media";

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("social_media", Mockery::type("array"))
            ->andReturn("Social output");

        $result = $this->action->execute($structure);

        $this->assertSame("Social output", $result["content"]);
    }

    /**
     * An empty workflow field should fall back to "universal_documentation".
     */
    #[Test]
    public function execute_resolves_empty_workflow_to_universal_documentation(): void
    {
        $structure = $this->structure;
        $structure["tracks"]["blog.com/blog/en"]["workflow"] = "";

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("universal_documentation", Mockery::type("array"))
            ->andReturn("Output");

        $result = $this->action->execute($structure);

        $this->assertSame("Output", $result["content"]);
    }

    /**
     * An unknown workflow identifier should fall back to "universal_documentation".
     */
    #[Test]
    public function execute_resolves_unknown_workflow_to_universal_documentation(): void
    {
        $structure = $this->structure;
        $structure["tracks"]["blog.com/blog/en"]["workflow"] =
            "some_future_type";

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("universal_documentation", Mockery::type("array"))
            ->andReturn("Output");

        $result = $this->action->execute($structure);

        $this->assertSame("Output", $result["content"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Context building
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The context passed to the executor should contain the expected keys
     * derived from the structure and the first track.
     */
    #[Test]
    public function execute_builds_context_from_structure_and_first_track(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "universal_documentation",
                Mockery::on(function (array $ctx): bool {
                    return $ctx["title"] ===
                        "Can AI models be deployed on-premises?" &&
                        $ctx["title_slug"] ===
                            "can-ai-models-be-deployed-on-premises" &&
                        $ctx["domain_dir"] ===
                            "/tmp/agents/abc-123-def/2026_11_10_120000" &&
                        $ctx["assets_dir"] ===
                            "/tmp/agents/abc-123-def/2026_11_10_120000/assets" &&
                        $ctx["research_dir"] ===
                            "/tmp/research/abc-123-def/2026_11_10_120000" &&
                        $ctx["research_text"] ===
                            "Research about AI deployment." &&
                        $ctx["type"] === "blog" &&
                        $ctx["lang"] === "en";
                }),
            )
            ->andReturn("Output");

        $this->action->execute($this->structure);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Structure passthrough
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The returned structure should preserve all original keys and add
     * only the "content" key.
     */
    #[Test]
    public function execute_preserves_original_keys_and_adds_content(): void
    {
        $this->executor->shouldReceive("execute")->once()->andReturn("Output");

        $result = $this->action->execute($this->structure);

        // Original keys preserved.
        $this->assertSame($this->structure["uuid"], $result["uuid"]);
        $this->assertSame($this->structure["title"], $result["title"]);
        $this->assertSame(
            $this->structure["title_slug"],
            $result["title_slug"],
        );
        $this->assertSame(
            $this->structure["domain_dir"],
            $result["domain_dir"],
        );
        $this->assertSame(
            $this->structure["assets_dir"],
            $result["assets_dir"],
        );
        $this->assertSame(
            $this->structure["research_dir"],
            $result["research_dir"],
        );
        $this->assertSame($this->structure["tracks"], $result["tracks"]);

        // New "content" key added.
        $this->assertArrayHasKey("content", $result);
        $this->assertSame("Output", $result["content"]);
    }

    /**
     * The executor should be called exactly once with the full structure
     * (not per-track).
     */
    #[Test]
    public function execute_calls_executor_exactly_once(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->andReturn("# Full article content");

        $result = $this->action->execute($this->structure);

        $this->assertIsString($result["content"]);
        $this->assertNotSame("", $result["content"]);
    }
}
