<?php

namespace Tests\Unit\PncServices\Agent\Actions;

use App\PncAgentics\Workflows\Types\SocialMediaWorkflow;
use App\PncAgentics\Workflows\Types\UniversalDocumentationWorkflow;
use App\PncServices\Agent\Actions\WorkFlowAgentContentAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see WorkFlowAgentContentAction}.
 *
 * Verifies that the correct workflow is selected based on the first
 * track's "workflow" field, that the full structure is passed through
 * unmodified, and that the structure is returned unchanged.
 *
 * @covers \App\PncServices\Agent\Actions\WorkFlowAgentContentAction
 */
class WorkFlowAgentContentActionTest extends TestCase
{
    private UniversalDocumentationWorkflow $universalWorkflow;
    private SocialMediaWorkflow $socialMediaWorkflow;
    private WorkFlowAgentContentAction $action;

    /**
     * @var array<string, mixed>
     */
    private array $structure = [
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
                "template" => "social",
                "track" => "social_media",
                "track_type" => "social",
                "workflow" => "default",
                "lang" => "en",
                "content_json" => "/tmp/agents/abc-123-def/blog-content.json",
                "content_md" => "/tmp/agents/abc-123-def/blog-content.md",
                "description_md" =>
                    "/tmp/agents/abc-123-def/blog-description.md",
                "research_text" => "Research about AI deployment.",
            ],
            "meeting.com/meeting/en" => [
                "domain" => "meeting.com",
                "type" => "meeting",
                "template" => "social",
                "track" => "social_media",
                "track_type" => "social",
                "workflow" => "default",
                "lang" => "en",
                "content_json" =>
                    "/tmp/agents/abc-123-def/meeting-content.json",
                "content_md" => "/tmp/agents/abc-123-def/meeting-content.md",
                "description_md" =>
                    "/tmp/agents/abc-123-def/meeting-description.md",
                "research_text" => "Research about AI in meetings.",
            ],
        ],
    ];

    protected function setUp(): void
    {
        $this->universalWorkflow = \Mockery::mock(
            UniversalDocumentationWorkflow::class,
        );
        $this->socialMediaWorkflow = \Mockery::mock(SocialMediaWorkflow::class);

        $this->action = new WorkFlowAgentContentAction(
            $this->universalWorkflow,
            $this->socialMediaWorkflow,
        );
    }

    protected function tearDown(): void
    {
        \Mockery::close();
    }

    /**
     * Verifies that a "default" workflow routes to UniversalDocumentationWorkflow
     * and the full structure is passed as the sole argument.
     */
    #[Test]
    public function execute_routes_default_workflow_to_universal(): void
    {
        $this->universalWorkflow
            ->shouldReceive("execute")
            ->once()
            ->with($this->structure)
            ->andReturn("Generated output");

        $this->socialMediaWorkflow->shouldNotReceive("execute");

        $result = $this->action->execute($this->structure);

        $this->assertSame($this->structure, $result);
    }

    /**
     * Verifies that a "social_media" workflow routes to SocialMediaWorkflow.
     */
    #[Test]
    public function execute_routes_social_media_workflow_to_social(): void
    {
        $structure = $this->structure;
        $structure["tracks"]["blog.com/blog/en"]["workflow"] = "social_media";

        $this->socialMediaWorkflow
            ->shouldReceive("execute")
            ->once()
            ->with($structure)
            ->andReturn("Social output");

        $this->universalWorkflow->shouldNotReceive("execute");

        $result = $this->action->execute($structure);

        $this->assertSame($structure, $result);
    }

    /**
     * Verifies that an empty workflow string is treated as "default".
     */
    #[Test]
    public function execute_routes_empty_workflow_to_universal(): void
    {
        $structure = $this->structure;
        $structure["tracks"]["blog.com/blog/en"]["workflow"] = "";

        $this->universalWorkflow
            ->shouldReceive("execute")
            ->once()
            ->with($structure)
            ->andReturn("Output");

        $this->socialMediaWorkflow->shouldNotReceive("execute");

        $result = $this->action->execute($structure);

        $this->assertSame($structure, $result);
    }

    /**
     * Verifies that an unknown workflow identifier falls back to
     * UniversalDocumentationWorkflow.
     */
    #[Test]
    public function execute_routes_unknown_workflow_to_universal(): void
    {
        $structure = $this->structure;
        $structure["tracks"]["blog.com/blog/en"]["workflow"] =
            "some_future_type";

        $this->universalWorkflow
            ->shouldReceive("execute")
            ->once()
            ->with($structure)
            ->andReturn("Output");

        $this->socialMediaWorkflow->shouldNotReceive("execute");

        $result = $this->action->execute($structure);

        $this->assertSame($structure, $result);
    }

    /**
     * Verifies that the structure is returned completely unmodified.
     */
    #[Test]
    public function execute_returns_structure_unmodified(): void
    {
        $this->universalWorkflow
            ->shouldReceive("execute")
            ->once()
            ->andReturn("Output");

        $result = $this->action->execute($this->structure);

        $this->assertSame($this->structure["uuid"], $result["uuid"]);
        $this->assertSame($this->structure["title"], $result["title"]);
        $this->assertSame(
            $this->structure["domain_dir"],
            $result["domain_dir"],
        );
        $this->assertSame($this->structure["tracks"], $result["tracks"]);
    }

    /**
     * Verifies that the workflow is called exactly once with the full
     * structure (not per-track).
     */
    #[Test]
    public function execute_calls_workflow_once_with_full_structure(): void
    {
        $this->universalWorkflow
            ->shouldReceive("execute")
            ->once()
            ->withArgs(function (array $structure) {
                return $structure["uuid"] === "abc-123-def" &&
                    count($structure["tracks"]) === 2 &&
                    isset($structure["tracks"]["blog.com/blog/en"]) &&
                    isset($structure["tracks"]["meeting.com/meeting/en"]);
            })
            ->andReturn("Output");

        $this->action->execute($this->structure);

        $this->addToAssertionCount(1);
    }
}
