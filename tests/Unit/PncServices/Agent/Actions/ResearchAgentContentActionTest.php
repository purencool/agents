<?php

namespace Tests\Unit\PncServices\Agent\Actions;

use App\PncAgentics\Workflows\ResearchGatherer;
use App\PncServices\Agent\Actions\ResearchAgentContentAction;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see ResearchAgentContentAction}.
 *
 * Verifies that the gatherer is called once per track with the correct
 * payload (title + domain) and research_dir, that results are stored
 * in the structure, and that an exception is thrown when research_dir
 * is missing.
 *
 * @covers \App\PncServices\Agent\Actions\ResearchAgentContentAction
 */
class ResearchAgentContentActionTest extends TestCase
{
    private ResearchGatherer $gatherer;

    private ResearchAgentContentAction $action;

    /**
     * Mock structure matching CreateAgentStructureAction's documented return shape.
     *
     * @var array{
     *     uuid: string,
     *     title: string,
     *     title_slug: string,
     *     domain_dir: string,
     *     assets_dir: string,
     *     research_dir: string,
     *     tracks: array<string, array{
     *         domain: string,
     *         type: string,
     *         template: string,
     *         track: string,
     *         track_type: string,
     *         workflow: string,
     *         lang: string,
     *         content_json: string,
     *         content_md: string,
     *         description_md: string
     *     }>
     * }
     */
    private array $structure = [
        "uuid" => "abc-123-def",
        "title" => "Can AI models be deployed on-premises?",
        "title_slug" => "can_ai_models_be_deployed_on_premises",
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

    /**
     * Sets up mocks and the action under test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->gatherer = \Mockery::mock(ResearchGatherer::class);
        $this->action = new ResearchAgentContentAction($this->gatherer);

        Log::shouldReceive("channel")
            ->with("daily")
            ->andReturn(
                \Mockery::mock()->shouldReceive("info")->byDefault()->mock(),
            );
    }

    /**
     * Closes Mockery after each test.
     */
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Verifies the gatherer is called once per track with the correct
     * payload (title + domain) and the research_dir as target directory.
     */
    #[Test]
    public function execute_calls_gatherer_once_per_track_with_correct_args(): void
    {
        $this->gatherer
            ->shouldReceive("gather")
            ->twice()
            ->withArgs(function (array $payload, string $dir) {
                return $dir === $this->structure["research_dir"] &&
                    $payload["title"] === $this->structure["title"] &&
                    isset($payload["domain"]);
            })
            ->andReturn("research text");

        $result = $this->action->execute($this->structure);

        $this->assertCount(2, $result["tracks"]);
    }

    /**
     * Verifies the correct domain is passed in the payload for each track.
     */
    #[Test]
    public function execute_passes_correct_domain_per_track(): void
    {
        $capturedDomains = [];

        $this->gatherer
            ->shouldReceive("gather")
            ->twice()
            ->withArgs(function (array $payload, string $dir) use (
                &$capturedDomains,
            ) {
                $capturedDomains[] = $payload["domain"];
                return true;
            })
            ->andReturn("text");

        $this->action->execute($this->structure);

        $this->assertSame(["blog.com", "meeting.com"], $capturedDomains);
    }

    /**
     * Verifies the research text returned by the gatherer is stored
     * under "research_text" in each track.
     */
    #[Test]
    public function execute_stores_research_text_in_each_track(): void
    {
        $this->gatherer
            ->shouldReceive("gather")
            ->twice()
            ->andReturn("combined research data");

        $result = $this->action->execute($this->structure);

        foreach ($result["tracks"] as $key => $track) {
            $this->assertArrayHasKey(
                "research_text",
                $track,
                "Missing research_text for {$key}",
            );
            $this->assertSame(
                "combined research data",
                $track["research_text"],
            );
        }
    }

    /**
     * Verifies the original structure keys are preserved in the return value.
     */
    #[Test]
    public function execute_preserves_original_structure_keys(): void
    {
        $this->gatherer->shouldReceive("gather")->twice()->andReturn("text");

        $result = $this->action->execute($this->structure);

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
    }

    /**
     * Verifies a RuntimeException is thrown when "research_dir" is missing.
     */
    #[Test]
    public function execute_throws_when_research_dir_missing(): void
    {
        $structure = $this->structure;
        unset($structure["research_dir"]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing 'research_dir'");

        $this->action->execute($structure);
    }
}
