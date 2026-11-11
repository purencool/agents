<?php

namespace Tests\Unit\PncServices\Agent;

use App\PncServices\Agent\AgentDataService;
use App\PncServices\Agent\Actions\CreateAgentStructureAction;
use App\PncServices\Agent\Actions\ResearchAgentContentAction;
use App\PncServices\Agent\Actions\WorkFlowAgentContentAction;
use App\PncServices\Agent\Actions\TemplateAgentContentAction;
use App\PncServices\Agent\Actions\SaveAgentContentAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AgentDataService}.
 *
 * Verifies that each pipeline stage delegates correctly to its Action
 * and that {@see AgentDataService::executeFullCreationPipeline()} returns
 * the documented array shape.
 *
 * @covers \App\PncServices\Agent\AgentDataService
 */
class AgentDataServiceTest extends TestCase
{
    private CreateAgentStructureAction $createStructureAction;
    private ResearchAgentContentAction $researchAction;
    private WorkFlowAgentContentAction $workFlowAction;
    private TemplateAgentContentAction $templateAction;
    private SaveAgentContentAction $saveAction;

    private AgentDataService $service;

    /**
     * Shared fixture payload loaded from tests/Fixtures/input_data/agent_data_payload.php.
     *
     * @var array{title: string, description: string, domains: array}
     */
    private array $payload;

    /**
     * Mock structure returned by createStructureAction.
     *
     * @var array{uuid: string, title: string, domain_dir: string, assets_dir: string, research_dir: string, tracks: array<string, array>}
     */
    private array $mockStructure = [
        "uuid" => "abc-123-def",
        "title" => "Can AI models be deployed on-premises?",
        "domain_dir" => "/tmp/agents/abc-123-def/2026_11_10_120000",
        "assets_dir" => "/tmp/agents/abc-123-def/2026_11_10_120000/assets",
        "research_dir" => "/tmp/research/abc-123-def/2026_11_10_120000",
        "tracks" => [
            "blog.com/blog/en" => [
                "domain" => "blog.com",
                "type" => "blog",
                "template" => "social",
                "track" => "social_media",
                "track_type" => "social",
                "workflow" => "default",
                "lang" => "en",
                "content_json" =>
                    "/tmp/agents/abc-123-def/blogcom-test-blog-en-content.json",
                "content_md" =>
                    "/tmp/agents/abc-123-def/blogcom-test-blog-en-content.md",
                "description_md" =>
                    "/tmp/agents/abc-123-def/blogcom-test-blog-en-description.md",
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
                    "/tmp/agents/abc-123-def/meetingcom-test-meeting-en-content.json",
                "content_md" =>
                    "/tmp/agents/abc-123-def/meetingcom-test-meeting-en-content.md",
                "description_md" =>
                    "/tmp/agents/abc-123-def/meetingcom-test-meeting-en-description.md",
            ],
        ],
    ];

    protected function setUp(): void
    {
        $this->payload = require __DIR__ .
            "/../../../Fixtures/input_data/agent_data_payload.php";

        $this->createStructureAction = \Mockery::mock(
            CreateAgentStructureAction::class,
        );
        $this->researchAction = \Mockery::mock(
            ResearchAgentContentAction::class,
        );
        $this->workFlowAction = \Mockery::mock(
            WorkFlowAgentContentAction::class,
        );
        $this->templateAction = \Mockery::mock(
            TemplateAgentContentAction::class,
        );
        $this->saveAction = \Mockery::mock(SaveAgentContentAction::class);

        $this->service = new AgentDataService(
            $this->createStructureAction,
            $this->researchAction,
            $this->workFlowAction,
            $this->templateAction,
            $this->saveAction,
        );
    }

    protected function tearDown(): void
    {
        \Mockery::close();
    }

    /**
     * Verifies that createStructure() delegates to CreateAgentStructureAction
     * and returns the action's result unmodified.
     */
    #[Test]
    public function createStructure_delegates_to_action_and_returns_result(): void
    {
        $this->createStructureAction
            ->shouldReceive("execute")
            ->once()
            ->with($this->payload)
            ->andReturn($this->mockStructure);

        $result = $this->service->createStructure($this->payload);

        $this->assertSame($this->mockStructure, $result);
    }

    /**
     * Verifies that runResearch() delegates to ResearchAgentContentAction.
     */
    #[Test]
    public function runResearch_delegates_to_action_and_returns_result(): void
    {
        $expected = $this->mockStructure;
        $expected["tracks"]["blog.com/blog/en"]["research_text"] =
            "research data";

        $this->researchAction
            ->shouldReceive("execute")
            ->once()
            ->with($this->mockStructure)
            ->andReturn($expected);

        $result = $this->service->runResearch($this->mockStructure);

        $this->assertSame($expected, $result);
    }

    /**
     * Verifies that runWorkFlow() delegates to WorkFlowAgentContentAction.
     */
    #[Test]
    public function runWorkFlow_delegates_to_action_and_returns_result(): void
    {
        $expected = $this->mockStructure;

        $this->workFlowAction
            ->shouldReceive("execute")
            ->once()
            ->with($this->mockStructure)
            ->andReturn($expected);

        $result = $this->service->runWorkFlow($this->mockStructure);

        $this->assertSame($expected, $result);
    }

    /**
     * Verifies that applyTemplate() delegates to TemplateAgentContentAction.
     */
    #[Test]
    public function applyTemplate_delegates_to_action_and_returns_result(): void
    {
        $expected = $this->mockStructure;

        $this->templateAction
            ->shouldReceive("execute")
            ->once()
            ->with($this->mockStructure)
            ->andReturn($expected);

        $result = $this->service->applyTemplate($this->mockStructure);

        $this->assertSame($expected, $result);
    }

    /**
     * Verifies that save() delegates to SaveAgentContentAction.
     */
    #[Test]
    public function save_delegates_to_action_and_returns_result(): void
    {
        $expected = $this->mockStructure;

        $this->saveAction
            ->shouldReceive("execute")
            ->once()
            ->with($this->mockStructure)
            ->andReturn($expected);

        $result = $this->service->save($this->mockStructure);

        $this->assertSame($expected, $result);
    }

    /**
     * Verifies that executeFullCreationPipeline() calls createStructure
     * and runResearch in sequence, wrapping the result under the "structure" key.
     */
    #[Test]
    public function executeFullCreationPipeline_calls_createStructure_and_research(): void
    {
        $this->createStructureAction
            ->shouldReceive("execute")
            ->once()
            ->with($this->payload)
            ->andReturn($this->mockStructure);

        $this->researchAction
            ->shouldReceive("execute")
            ->once()
            ->with($this->mockStructure)
            ->andReturn($this->mockStructure);

        $this->workFlowAction
            ->shouldReceive("execute")
            ->once()
            ->with($this->mockStructure)
            ->andReturn($this->mockStructure);

        $result = $this->service->executeFullCreationPipeline($this->payload);

        $this->assertArrayHasKey("structure", $result);
        $this->assertSame($this->mockStructure, $result["structure"]);
        $this->assertSame("abc-123-def", $result["structure"]["uuid"]);
    }

    /**
     * Verifies that executeFullCreationPipeline() does NOT call the
     * downstream stages (workflow, template, save) while they
     * are still commented out.
     */
    #[Test]
    public function executeFullCreationPipeline_does_not_call_uncommented_stages(): void
    {
        $this->createStructureAction
            ->shouldReceive("execute")
            ->once()
            ->with($this->payload)
            ->andReturn($this->mockStructure);

        $this->researchAction
            ->shouldReceive("execute")
            ->once()
            ->andReturn($this->mockStructure);

        $this->workFlowAction
            ->shouldReceive("execute")
            ->once()
            ->andReturn($this->mockStructure);
        $this->templateAction->shouldNotReceive("execute");
        $this->saveAction->shouldNotReceive("execute");

        $result = $this->service->executeFullCreationPipeline($this->payload);

        $this->assertArrayHasKey("structure", $result);
    }
}
