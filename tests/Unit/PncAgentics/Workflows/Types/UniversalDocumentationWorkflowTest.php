<?php

namespace Tests\Unit\PncAgentics\Workflows\Types;

use App\PncAgentics\Contracts\FileSaverInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Workflows\Types\UniversalDocumentationWorkflow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see UniversalDocumentationWorkflow}.
 *
 * Verifies that the multi-stage skill pipeline is invoked in the correct
 * order with the correct skill keys and payloads, that output is saved
 * via FileSaver, and that the final polished markdown is returned.
 *
 * @covers \App\PncAgentics\Workflows\Types\UniversalDocumentationWorkflow
 */
class UniversalDocumentationWorkflowTest extends TestCase
{
    private SkillExecutorInterface $executor;
    private FileSaverInterface $saver;
    private UniversalDocumentationWorkflow $workflow;

    /**
     * Minimal single-track structure for focused tests.
     *
     * @var array<string, mixed>
     */
    private array $singleTrackStructure = [
        "uuid" => "test-uuid",
        "domain_dir" => "/tmp/agents/test-uuid",
        "assets_dir" => "/tmp/agents/test-uuid/assets",
        "research_dir" => "/tmp/research/test-uuid",
        "title" => "Test Article Title",
        "title_slug" => "test-article-title",
        "tracks" => [
            "example.com/blog/en" => [
                "domain" => "example.com",
                "type" => "blog",
                "template" => "social",
                "track" => "social_media",
                "track_type" => "social",
                "workflow" => "default",
                "lang" => "en",
                "content_json" => "/tmp/agents/test-uuid/content.json",
                "content_md" => "/tmp/agents/test-uuid/content.md",
                "description_md" => "/tmp/agents/test-uuid/description.md",
                "research_text" => "Pre-gathered research data.",
            ],
        ],
    ];

    protected function setUp(): void
    {
        $this->executor = \Mockery::mock(SkillExecutorInterface::class);
        $this->saver = \Mockery::mock(FileSaverInterface::class);

        $this->workflow = new UniversalDocumentationWorkflow(
            $this->executor,
            $this->saver,
        );
    }

    protected function tearDown(): void
    {
        \Mockery::close();
    }

    /**
     * Verifies that the outline generator is called with the correct
     * skill key, payload fields, and temperature.
     */
    #[Test]
    public function execute_calls_outline_generator_with_correct_arguments(): void
    {
        $this->mockFullPipeline();

        $this->workflow->execute($this->singleTrackStructure);

        $this->addToAssertionCount(1);
    }

    /**
     * Verifies that the document orchestrator receives the raw outline
     * and a format instruction.
     */
    #[Test]
    public function execute_calls_orchestrator_with_outline_and_format_instruction(): void
    {
        $this->mockFullPipeline();

        $this->workflow->execute($this->singleTrackStructure);

        $this->addToAssertionCount(1);
    }

    /**
     * Verifies that for each section in the outline, all five body-content
     * skills are called in sequence: draft → analogy → critique → expand → smooth.
     */
    #[Test]
    public function execute_calls_all_body_content_skills_per_section(): void
    {
        $this->mockFullPipeline();

        $this->workflow->execute($this->singleTrackStructure);

        $this->addToAssertionCount(1);
    }

    /**
     * Verifies that the introduction writer receives the title, description,
     * body context, and refinement context.
     */
    #[Test]
    public function execute_calls_introduction_writer_with_correct_payload(): void
    {
        $this->mockFullPipeline();

        $this->workflow->execute($this->singleTrackStructure);

        $this->addToAssertionCount(1);
    }

    /**
     * Verifies that the conclusion writer receives the introduction, body,
     * and the track's "type" as tone.
     */
    #[Test]
    public function execute_calls_conclusion_writer_with_type_as_tone(): void
    {
        $this->mockFullPipeline();

        $this->workflow->execute($this->singleTrackStructure);

        $this->addToAssertionCount(1);
    }

    /**
     * Verifies that the formatting expert receives the assembled master draft.
     */
    #[Test]
    public function execute_calls_formatting_expert_with_master_draft(): void
    {
        $this->mockFullPipeline();

        $this->workflow->execute($this->singleTrackStructure);

        $this->addToAssertionCount(1);
    }

    /**
     * Verifies that the saver is called with the domain_dir path and the
     * track's content_md basename (without extension).
     */
    #[Test]
    public function execute_saves_markdown_with_correct_path_and_filename(): void
    {
        $this->mockFullPipeline();

        $result = $this->workflow->execute($this->singleTrackStructure);

        $this->assertSame("Final polished output", $result);
    }

    /**
     * Verifies that the returned string is the output of the LAST track
     * when multiple tracks are present.
     */
    #[Test]
    public function execute_returns_output_of_last_track(): void
    {
        $structure = [
            "uuid" => "multi-uuid",
            "domain_dir" => "/tmp/agents/multi",
            "assets_dir" => "/tmp/agents/multi/assets",
            "research_dir" => "/tmp/research/multi",
            "title" => "Multi Track",
            "title_slug" => "multi-track",
            "tracks" => [
                "a.com/blog/en" => [
                    "domain" => "a.com",
                    "type" => "blog",
                    "template" => "social",
                    "track" => "social_media",
                    "track_type" => "social",
                    "workflow" => "default",
                    "lang" => "en",
                    "content_json" => "/tmp/agents/multi/a.json",
                    "content_md" => "/tmp/agents/multi/a.md",
                    "description_md" => "/tmp/agents/multi/a-desc.md",
                    "research_text" => "Research A.",
                ],
                "b.com/meeting/en" => [
                    "domain" => "b.com",
                    "type" => "meeting",
                    "template" => "social",
                    "track" => "social_media",
                    "track_type" => "social",
                    "workflow" => "default",
                    "lang" => "en",
                    "content_json" => "/tmp/agents/multi/b.json",
                    "content_md" => "/tmp/agents/multi/b.md",
                    "description_md" => "/tmp/agents/multi/b-desc.md",
                    "research_text" => "Research B.",
                ],
            ],
        ];

        $callCount = 0;

        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $payload,
                array $opts,
            ) use (&$callCount) {
                $callCount++;
                if (str_contains($skillKey, "formatting_markdown_expert")) {
                    return $callCount > 10
                        ? "Track B output"
                        : "Track A output";
                }
                if (str_contains($skillKey, "document_orchestrator")) {
                    return '["Section 1"]';
                }
                return "placeholder";
            });

        $this->saver
            ->shouldReceive("setPath")
            ->twice()
            ->with("/tmp/agents/multi");
        $this->saver->shouldReceive("saveMarkdown")->twice();

        $result = $this->workflow->execute($structure);

        $this->assertSame("Track B output", $result);
    }

    /**
     * Verifies that when the orchestrator returns invalid JSON, the
     * fallback uses the title as a single section.
     */
    #[Test]
    public function execute_falls_back_to_title_when_orchestrator_returns_invalid_json(): void
    {
        $sectionCallCount = 0;

        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $payload,
                array $opts,
            ) use (&$sectionCallCount) {
                if (str_contains($skillKey, "document_orchestrator")) {
                    return "not valid json at all";
                }
                if (str_contains($skillKey, "batch_draft_engine")) {
                    $sectionCallCount++;
                }
                if (str_contains($skillKey, "formatting_markdown_expert")) {
                    return "Fallback output";
                }
                return "placeholder";
            });

        $this->saver->shouldReceive("setPath")->once();
        $this->saver
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("Fallback output", "content");

        $result = $this->workflow->execute($this->singleTrackStructure);

        // Should have called draft engine once (single fallback section = title)
        $this->assertSame(1, $sectionCallCount);
        $this->assertSame("Fallback output", $result);
    }

    /**
     * Verifies that the research_text is included in the refinement context
     * passed to all skill calls.
     */
    #[Test]
    public function execute_includes_research_text_in_refinement_context(): void
    {
        $capturedContext = null;

        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $payload,
                array $opts,
            ) use (&$capturedContext) {
                if (
                    str_contains($skillKey, "outline_generator") &&
                    $capturedContext === null
                ) {
                    $capturedContext = $payload["refinement_context"] ?? null;
                }
                if (str_contains($skillKey, "document_orchestrator")) {
                    return '["Section 1"]';
                }
                if (str_contains($skillKey, "formatting_markdown_expert")) {
                    return "Output";
                }
                return "placeholder";
            });

        $this->saver->shouldReceive("setPath")->once();
        $this->saver->shouldReceive("saveMarkdown")->once();

        $this->workflow->execute($this->singleTrackStructure);

        $this->assertStringContainsString(
            "## Research Findings",
            $capturedContext,
        );
        $this->assertStringContainsString(
            "Pre-gathered research data.",
            $capturedContext,
        );
    }

    /**
     * Verifies that the skill key uses the track key as its base namespace.
     */
    #[Test]
    public function execute_uses_track_key_as_base_skill_key(): void
    {
        $capturedSkillKey = null;

        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $payload,
                array $opts,
            ) use (&$capturedSkillKey) {
                if ($capturedSkillKey === null) {
                    $capturedSkillKey = $skillKey;
                }
                if (str_contains($skillKey, "document_orchestrator")) {
                    return '["Section 1"]';
                }
                if (str_contains($skillKey, "formatting_markdown_expert")) {
                    return "Output";
                }
                return "placeholder";
            });

        $this->saver->shouldReceive("setPath")->once();
        $this->saver->shouldReceive("saveMarkdown")->once();

        $this->workflow->execute($this->singleTrackStructure);

        $this->assertSame(
            "example.com/blog/en.research_preparation.outline_generator.skills",
            $capturedSkillKey,
        );
    }

    /**
     * Sets up the full pipeline mock for a single-track structure with
     * one section, verifying each skill key and key payload fields.
     */
    private function mockFullPipeline(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.research_preparation.outline_generator.skills",
                \Mockery::on(function (array $p) {
                    return $p["title"] === "Test Article Title" &&
                        $p["description"] === "Pre-gathered research data." &&
                        str_contains(
                            $p["refinement_context"],
                            "Pre-gathered research data.",
                        );
                }),
                ["temperature" => 0.2],
            )
            ->andReturn("Raw outline text");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.orchestration.document_orchestrator.skills",
                \Mockery::on(function (array $p) {
                    return $p["outline"] === "Raw outline text" &&
                        str_contains(
                            $p["format_instruction"],
                            "plain JSON array",
                        );
                }),
                ["temperature" => 0.1],
            )
            ->andReturn('["Introduction", "Main Body"]');

        // Section 1: "Introduction"
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.body_content.batch_draft_engine.skills",
                \Mockery::on(fn(array $p) => $p["section"] === "Introduction"),
                ["temperature" => 0.2],
            )
            ->andReturn("Draft 1");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.body_content.analogy_example_creator.skills",
                \Mockery::on(
                    fn(array $p) => $p["section"] === "Introduction" &&
                        $p["content"] === "Draft 1",
                ),
                ["temperature" => 0.3],
            )
            ->andReturn("Insight 1");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.body_content.counter_argument_builder.skills",
                \Mockery::on(
                    fn(array $p) => $p["section"] === "Introduction" &&
                        $p["content"] === "Insight 1",
                ),
                ["temperature" => 0.2],
            )
            ->andReturn("Critique 1");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.body_content.batch_expand_engine.skills",
                \Mockery::on(
                    fn(array $p) => $p["section"] === "Introduction" &&
                        $p["content"] === "Critique 1",
                ),
                ["temperature" => 0.2],
            )
            ->andReturn("Expanded 1");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.body_content.internal_transition_smoother.skills",
                \Mockery::on(
                    fn(array $p) => $p["content"] ===
                        "Critique 1\n\nExpanded 1",
                ),
                ["temperature" => 0.2],
            )
            ->andReturn("Polished 1");

        // Section 2: "Main Body"
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.body_content.batch_draft_engine.skills",
                \Mockery::on(fn(array $p) => $p["section"] === "Main Body"),
                ["temperature" => 0.2],
            )
            ->andReturn("Draft 2");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.body_content.analogy_example_creator.skills",
                \Mockery::on(
                    fn(array $p) => $p["section"] === "Main Body" &&
                        $p["content"] === "Draft 2",
                ),
                ["temperature" => 0.3],
            )
            ->andReturn("Insight 2");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.body_content.counter_argument_builder.skills",
                \Mockery::on(
                    fn(array $p) => $p["section"] === "Main Body" &&
                        $p["content"] === "Insight 2",
                ),
                ["temperature" => 0.2],
            )
            ->andReturn("Critique 2");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.body_content.batch_expand_engine.skills",
                \Mockery::on(
                    fn(array $p) => $p["section"] === "Main Body" &&
                        $p["content"] === "Critique 2",
                ),
                ["temperature" => 0.2],
            )
            ->andReturn("Expanded 2");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.body_content.internal_transition_smoother.skills",
                \Mockery::on(
                    fn(array $p) => $p["content"] ===
                        "Critique 2\n\nExpanded 2",
                ),
                ["temperature" => 0.2],
            )
            ->andReturn("Polished 2");

        // Introduction
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.hook_introduction_writer.skills",
                \Mockery::on(function (array $p) {
                    return $p["title"] === "Test Article Title" &&
                        $p["description"] === "Pre-gathered research data." &&
                        str_contains($p["body_context"], "## Introduction") &&
                        str_contains($p["body_context"], "## Main Body");
                }),
                ["temperature" => 0.4],
            )
            ->andReturn("Generated introduction");

        // Conclusion
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.core_writing.conclusion_writer.skills",
                \Mockery::on(function (array $p) {
                    return $p["introduction"] === "Generated introduction" &&
                        $p["tone"] === "blog";
                }),
                ["temperature" => 0.3],
            )
            ->andReturn("Generated conclusion");

        // Formatting expert
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "example.com/blog/en.review_refinement.formatting_markdown_expert.skills",
                \Mockery::on(function (array $p) {
                    return str_contains(
                        $p["content"],
                        "# Test Article Title",
                    ) &&
                        str_contains($p["content"], "Generated introduction") &&
                        str_contains($p["content"], "## Conclusion") &&
                        str_contains($p["content"], "Generated conclusion");
                }),
                ["temperature" => 0.1],
            )
            ->andReturn("Final polished output");
        $this->saver
            ->shouldReceive("setPath")
            ->once()
            ->with("/tmp/agents/test-uuid");
        $this->saver
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("Final polished output", "content");
    }
}
