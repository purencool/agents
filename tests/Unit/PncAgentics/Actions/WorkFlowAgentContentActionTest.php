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
 *  - The full structure array is passed unchanged to JsonWorkflowExecutor::execute().
 *  - The executor's return value is returned directly (no mutation, no wrapping).
 *  - The executor is called exactly once regardless of track count.
 *
 * @covers \App\PncAgentics\Actions\WorkFlowAgentContentAction
 */
class WorkFlowAgentContentActionTest extends TestCase
{
    private JsonWorkflowExecutor $executor;

    private WorkFlowAgentContentAction $action;

    /**
     * A representative content structure matching CreateAgentStructureAction's return shape.
     *
     * @var array{
     *     uuid: string,
     *     title: string,
     *     title_slug: string,
     *     description: string,
     *     categories: string[],
     *     domain_dir: string,
     *     assets_dir: string,
     *     research_dir: string,
     *     tracks: array<string, array<string, array{
     *         uuid: string,
     *         title: string,
     *         categories: string[],
     *         workflow: string,
     *         lang: string,
     *         domain: string,
     *         context: string,
     *         type: string,
     *         template: string,
     *         track: string,
     *         track_type: string,
     *         content_json: string,
     *         content_path_json: string,
     *         content_html: string,
     *         content_path_html: string,
     *         content_markdown: string,
     *         content_path_markdown: string,
     *         content_description: string
     *     }>>
     * }
     */
    private array $structure;

    protected function setUp(): void
    {
        $this->executor = Mockery::mock(JsonWorkflowExecutor::class);
        $this->action = new WorkFlowAgentContentAction($this->executor);

        $this->structure = [
            "uuid" => "abc-123-def",
            "title" => "Can AI models be deployed on-premises?",
            "title_slug" => "can_ai_models_be_deployed_on_premises",
            "description" => "Test description",
            "categories" => ["AI", "LLMs", "Local Models"],
            "domain_dir" => "/tmp/agents/abc-123-def/2026_11_10_120000",
            "assets_dir" => "/tmp/agents/abc-123-def/2026_11_10_120000/assets",
            "research_dir" =>
                "/tmp/agents/abc-123-def/2026_11_10_120000/research",
            "tracks" => [
                "blog.com" => [
                    "blog-en" => [
                        "uuid" => "abc-123-def",
                        "title" => "Can AI models be deployed on-premises?",
                        "categories" => ["AI", "LLMs", "Local Models"],
                        "workflow" => "default",
                        "lang" => "en",
                        "domain" => "blog.com",
                        "context" => "This for marketing",
                        "type" => "blog",
                        "template" => "social",
                        "track" => "social_media",
                        "track_type" => "social",
                        "content_json" => "",
                        "content_path_json" =>
                            "/tmp/agents/abc-123-def/blogcom/blog-en/blogcom-test-blog-en-content.json",
                        "content_html" => "",
                        "content_path_html" => "",
                        "content_markdown" => "",
                        "content_path_markdown" =>
                            "/tmp/agents/abc-123-def/blogcom/blog-en/blogcom-test-blog-en-content.md",
                        "content_description" =>
                            "/tmp/agents/abc-123-def/blogcom/blog-en/blogcom-test-blog-en-description.md",
                    ],
                ],
                "meeting.com" => [
                    "meeting-en" => [
                        "uuid" => "abc-123-def",
                        "title" => "Can AI models be deployed on-premises?",
                        "categories" => ["AI", "LLMs", "Local Models"],
                        "workflow" => "default",
                        "lang" => "en",
                        "domain" => "meeting.com",
                        "context" => "This for security",
                        "type" => "meeting",
                        "template" => "social",
                        "track" => "social_media",
                        "track_type" => "social",
                        "content_json" => "",
                        "content_path_json" =>
                            "/tmp/agents/abc-123-def/meetingcom/meeting-en/meetingcom-test-meeting-en-content.json",
                        "content_html" => "",
                        "content_path_html" => "",
                        "content_markdown" => "",
                        "content_path_markdown" =>
                            "/tmp/agents/abc-123-def/meetingcom/meeting-en/meetingcom-test-meeting-en-content.md",
                        "content_description" =>
                            "/tmp/agents/abc-123-def/meetingcom/meeting-en/meetingcom-test-meeting-en-description.md",
                    ],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    // ─── Pass-through behaviour ──────────────────────────────────────────

    /**
     * The full structure array is passed unchanged to executor::execute().
     */
    #[Test]
    public function execute_passes_full_structure_to_executor(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->withArgs(function (array $structure): bool {
                return $structure === $this->structure;
            })
            ->andReturn([
                "workflow_results" => ["blog.com" => ["blog-en" => "output"]],
            ]);

        $this->action->execute($this->structure);
    }

    /**
     * The executor's return value is returned directly — no wrapping, no mutation.
     */
    #[Test]
    public function execute_returns_executor_result_directly(): void
    {
        $expected = [
            "uuid" => "abc-123-def",
            "title" => "Can AI models be deployed on-premises?",
            "workflow_results" => [
                "blog.com" => ["blog-en" => ["text" => "Generated content"]],
                "meeting.com" => [
                    "meeting-en" => ["text" => "Generated content"],
                ],
            ],
        ];

        $this->executor->shouldReceive("execute")->once()->andReturn($expected);

        $result = $this->action->execute($this->structure);

        $this->assertSame($expected, $result);
    }

    // ─── Invocation count ────────────────────────────────────────────────

    /**
     * The executor is called exactly once regardless of the number of tracks.
     */
    #[Test]
    public function execute_calls_executor_exactly_once(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->andReturn(["ok" => true]);

        $this->action->execute($this->structure);
    }

    /**
     * Even with a single track, the executor is called exactly once.
     */
    #[Test]
    public function execute_calls_executor_once_with_single_track(): void
    {
        $structure = $this->structure;
        // Remove all but one track
        $structure["tracks"] = [
            "blog.com" => $structure["tracks"]["blog.com"],
        ];

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->andReturn(["ok" => true]);

        $this->action->execute($structure);
    }

    // ─── No side effects ─────────────────────────────────────────────────

    /**
     * The structure array is not mutated — the action is a pure pass-through.
     */
    #[Test]
    public function execute_does_not_mutate_input_structure(): void
    {
        $original = $this->structure;

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->andReturn(["modified" => true]);

        $this->action->execute($this->structure);

        $this->assertSame($original, $this->structure);
    }
}
