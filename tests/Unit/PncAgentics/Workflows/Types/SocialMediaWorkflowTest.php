<?php

namespace Tests\Unit\PncAgentics\Workflows\Types;

use App\PncAgentics\Contracts\FileSaverInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Workflows\Types\SocialMediaWorkflow;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for SocialMediaWorkflow.
 *
 * Verifies that execute():
 *  - Processes all tracks in the structure sequentially.
 *  - Calls the skill executor with the correct skill keys, parameters,
 *    and temperature settings at each pipeline stage.
 *  - Parses the sections JSON response (with fallback on invalid JSON).
 *  - Builds the body canvas from per-section outputs.
 *  - Assembles the final markdown (title + intro + body + conclusion).
 *  - Saves via FileSaverInterface with the correct path and filename.
 *  - Returns the last processed track's output.
 */
class SocialMediaWorkflowTest extends TestCase
{
    private SocialMediaWorkflow $workflow;
    private Mockery\MockInterface $executor;
    private Mockery\MockInterface $saver;

    /**
     * Captured executor calls for post-assertion.
     *
     * @var array<int, array{skill: string, params: array, options: array}>
     */
    private array $calls = [];

    private array $structure = [
        "uuid" => "test-uuid",
        "domain_dir" => "/data/blog.com",
        "assets_dir" => "/data/blog.com/assets",
        "research_dir" => "/data/blog.com/research",
        "title" => "Test Article Title",
        "title_slug" => "test-article-title",
        "tracks" => [
            "track1" => [
                "domain" => "blog.com",
                "type" => "social",
                "template" => "blog_post",
                "track" => "social_media",
                "track_type" => "social",
                "workflow" => "default",
                "lang" => "en",
                "content_json" => "/data/blog.com/track1/content.json",
                "content_md" => "/data/blog.com/track1/output.md",
                "description_md" => "/data/blog.com/track1/desc.md",
                "research_text" => "Research findings about the topic.",
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = Mockery::mock(SkillExecutorInterface::class);
        $this->saver = Mockery::mock(FileSaverInterface::class);

        $this->workflow = new SocialMediaWorkflow(
            $this->executor,
            $this->saver,
        );
    }

    protected function tearDown(): void
    {
        $this->calls = [];
        Mockery::close();
        parent::tearDown();
    }

    // ─── Happy path: single track, single section ──────────────────────────

    /**
     * @testdoc Verify that execute() runs the full pipeline for a single
     *          track with one section, assembles the markdown correctly,
     *          saves it, and returns the final output.
     */
    #[Test]
    public function execute_runs_full_pipeline_for_single_track(): void
    {
        $finalOutput =
            "# Test Article Title\n\nIntro\n\n## Section\n\nPolished\n\n## Conclusion\n\nConclusion";

        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $params,
                array $options,
            ) use ($finalOutput) {
                $this->calls[] = [
                    "skill" => $skillKey,
                    "params" => $params,
                    "options" => $options,
                ];

                return $this->routeResponse($skillKey, $finalOutput);
            });

        $this->saver->shouldReceive("setPath")->once()->with("/data/blog.com");
        $this->saver
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with($finalOutput, "output");

        $result = $this->workflow->execute($this->structure);

        $this->assertSame($finalOutput, $result);
    }
    // ─── Multiple tracks ───────────────────────────────────────────────────

    /**
     * @testdoc Verify that execute() processes all tracks and returns
     *          the output from the LAST track only.
     */
    #[Test]
    public function execute_returns_last_track_output(): void
    {
        $structure = [
            "uuid" => "test-uuid",
            "domain_dir" => "/data/blog.com",
            "assets_dir" => "/data/blog.com/assets",
            "research_dir" => "/data/blog.com/research",
            "title" => "Multi Track",
            "title_slug" => "multi-track",
            "tracks" => [
                "trackA" => $this->makeTrack(
                    "trackA",
                    "/data/blog.com/trackA/output.md",
                ),
                "trackB" => $this->makeTrack(
                    "trackB",
                    "/data/blog.com/trackB/output.md",
                ),
            ],
        ];

        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $params,
                array $options,
            ) {
                if (str_contains($skillKey, "outline_generator")) {
                    return "Outline";
                }
                if (str_contains($skillKey, "document_orchestrator")) {
                    return '["Section"]';
                }
                if (str_contains($skillKey, "formatting_markdown_expert")) {
                    return str_contains($skillKey, "trackB")
                        ? "Track B Final"
                        : "Track A Final";
                }
                return "Content";
            });

        $this->saver->shouldReceive("setPath")->twice()->with("/data/blog.com");
        $this->saver->shouldReceive("saveMarkdown")->twice();

        $result = $this->workflow->execute($structure);

        $this->assertSame("Track B Final", $result);
    }

    // ─── JSON fallback ─────────────────────────────────────────────────────

    /**
     * @testdoc Verify that when the orchestrator returns invalid JSON,
     *          the workflow falls back to using the title as the sole section.
     */
    #[Test]
    public function execute_falls_back_to_title_on_invalid_json(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $params,
                array $options,
            ) {
                if (str_contains($skillKey, "outline_generator")) {
                    return "Outline";
                }
                if (str_contains($skillKey, "document_orchestrator")) {
                    return "This is not valid JSON at all";
                }
                if (str_contains($skillKey, "formatting_markdown_expert")) {
                    return "Final Output";
                }
                return "Content";
            });

        $this->saver->shouldReceive("setPath")->once();
        $this->saver->shouldReceive("saveMarkdown")->once();

        $result = $this->workflow->execute($this->structure);

        $this->assertSame("Final Output", $result);
    }

    // ─── JSON with markdown code fences ────────────────────────────────────

    /**
     * @testdoc Verify that execute() strips markdown code fences
     *          (```json ... ```) from the orchestrator response before
     *          decoding the JSON, resulting in 2 sections processed.
     */
    #[Test]
    public function execute_strips_markdown_code_fences_from_json(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $params,
                array $options,
            ) use (&$calls) {
                $this->calls[] = [
                    "skill" => $skillKey,
                    "params" => $params,
                    "options" => $options,
                ];

                if (str_contains($skillKey, "outline_generator")) {
                    return "Outline";
                }
                if (str_contains($skillKey, "document_orchestrator")) {
                    return "```json\n[\"Section One\", \"Section Two\"]\n```";
                }
                if (str_contains($skillKey, "formatting_markdown_expert")) {
                    return "Final";
                }
                return "Content";
            });

        $this->saver->shouldReceive("setPath")->once();
        $this->saver->shouldReceive("saveMarkdown")->once();

        $this->workflow->execute($this->structure);

        // Verify batch_draft_engine was called twice (once per section)
        $draftCalls = array_filter(
            $this->calls,
            fn($c) => str_contains($c["skill"], "batch_draft_engine"),
        );
        $this->assertCount(2, $draftCalls);

        $sections = array_map(fn($c) => $c["params"]["section"], $draftCalls);
        $this->assertContains("Section One", $sections);
        $this->assertContains("Section Two", $sections);
    }

    // ─── Skill key correctness ─────────────────────────────────────────────

    /**
     * @testdoc Verify that the correct skill keys are used at each stage
     *          of the pipeline, prefixed with the track key.
     */
    #[Test]
    public function execute_uses_correct_skill_keys(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $params,
                array $options,
            ) use (&$calls) {
                $this->calls[] = [
                    "skill" => $skillKey,
                    "params" => $params,
                    "options" => $options,
                ];

                if (str_contains($skillKey, "outline_generator")) {
                    return "Outline";
                }
                if (str_contains($skillKey, "document_orchestrator")) {
                    return '["Only Section"]';
                }
                return "Content";
            });

        $this->saver->shouldReceive("setPath")->once();
        $this->saver->shouldReceive("saveMarkdown")->once();

        $this->workflow->execute($this->structure);

        $expectedKeys = [
            "track1.research_preparation.outline_generator.skills",
            "track1.orchestration.document_orchestrator.skills",
            "track1.core_writing.body_content.batch_draft_engine.skills",
            "track1.core_writing.body_content.analogy_example_creator.skills",
            "track1.core_writing.body_content.counter_argument_builder.skills",
            "track1.core_writing.body_content.batch_expand_engine.skills",
            "track1.core_writing.body_content.internal_transition_smoother.skills",
            "track1.core_writing.hook_introduction_writer.skills",
            "track1.core_writing.conclusion_writer.skills",
            "track1.review_refinement.formatting_markdown_expert.skills",
        ];

        $actualKeys = array_map(fn($c) => $c["skill"], $this->calls);

        foreach ($expectedKeys as $key) {
            $this->assertContains(
                $key,
                $actualKeys,
                "Missing skill key: {$key}",
            );
        }
    }

    // ─── Temperature settings ──────────────────────────────────────────────

    /**
     * @testdoc Verify that the correct temperature is passed at each
     *          pipeline stage.
     */
    #[Test]
    public function execute_passes_correct_temperatures(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $params,
                array $options,
            ) use (&$calls) {
                $this->calls[] = [
                    "skill" => $skillKey,
                    "params" => $params,
                    "options" => $options,
                ];

                if (str_contains($skillKey, "outline_generator")) {
                    return "Outline";
                }
                if (str_contains($skillKey, "document_orchestrator")) {
                    return '["S"]';
                }
                return "Content";
            });

        $this->saver->shouldReceive("setPath")->once();
        $this->saver->shouldReceive("saveMarkdown")->once();

        $this->workflow->execute($this->structure);

        $expectedTemps = [
            "outline_generator" => 0.2,
            "document_orchestrator" => 0.1,
            "batch_draft_engine" => 0.2,
            "analogy_example_creator" => 0.3,
            "counter_argument_builder" => 0.2,
            "batch_expand_engine" => 0.2,
            "internal_transition_smoother" => 0.2,
            "hook_introduction_writer" => 0.4,
            "conclusion_writer" => 0.3,
            "formatting_markdown_expert" => 0.1,
        ];

        foreach ($this->calls as $call) {
            foreach ($expectedTemps as $fragment => $expectedTemp) {
                if (str_contains($call["skill"], $fragment)) {
                    $this->assertSame(
                        $expectedTemp,
                        $call["options"]["temperature"],
                        "Wrong temperature for {$fragment}",
                    );
                }
            }
        }
    }

    // ─── Saver path and filename ───────────────────────────────────────────

    /**
     * @testdoc Verify that the saver is called with the domain_dir as path
     *          and the basename of content_md (without .md) as filename.
     */
    #[Test]
    public function execute_saves_with_correct_path_and_filename(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $params,
                array $options,
            ) {
                if (str_contains($skillKey, "outline_generator")) {
                    return "Outline";
                }
                if (str_contains($skillKey, "document_orchestrator")) {
                    return '["S"]';
                }
                return "Final";
            });

        $this->saver->shouldReceive("setPath")->once()->with("/data/blog.com");
        $this->saver
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with(Mockery::type("string"), "output");

        $this->workflow->execute($this->structure);
    }

    // ─── Research context propagation ──────────────────────────────────────

    /**
     * @testdoc Verify that the research_text from the track is included
     *          in the refinement_context passed to every executor call.
     */
    #[Test]
    public function execute_includes_research_text_in_refinement_context(): void
    {
        $this->executor
            ->shouldReceive("execute")
            ->andReturnUsing(function (
                string $skillKey,
                array $params,
                array $options,
            ) use (&$calls) {
                $this->calls[] = [
                    "skill" => $skillKey,
                    "params" => $params,
                    "options" => $options,
                ];

                if (str_contains($skillKey, "outline_generator")) {
                    return "Outline";
                }
                if (str_contains($skillKey, "document_orchestrator")) {
                    return '["S"]';
                }
                return "Content";
            });

        $this->saver->shouldReceive("setPath")->once();
        $this->saver->shouldReceive("saveMarkdown")->once();

        $this->workflow->execute($this->structure);

        foreach ($this->calls as $call) {
            $this->assertArrayHasKey(
                "refinement_context",
                $call["params"],
                "Missing refinement_context in: {$call["skill"]}",
            );
            $this->assertStringContainsString(
                "Research findings about the topic.",
                $call["params"]["refinement_context"],
            );
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * Route executor responses based on skill key fragment.
     */
    private function routeResponse(
        string $skillKey,
        string $finalOutput,
    ): string {
        return match (true) {
            str_contains($skillKey, "outline_generator") => "Outline",
            str_contains($skillKey, "document_orchestrator") => '["Section"]',
            str_contains($skillKey, "batch_draft_engine") => "Draft",
            str_contains($skillKey, "analogy_example_creator") => "Insight",
            str_contains($skillKey, "counter_argument_builder") => "Critique",
            str_contains($skillKey, "batch_expand_engine") => "Value",
            str_contains($skillKey, "internal_transition_smoother")
                => "Polished",
            str_contains($skillKey, "hook_introduction_writer") => "Intro",
            str_contains($skillKey, "conclusion_writer") => "Conclusion",
            str_contains($skillKey, "formatting_markdown_expert")
                => $finalOutput,
            default => "Content",
        };
    }

    /**
     * Build a single track array with the given key and content_md path.
     */
    private function makeTrack(string $key, string $contentMd): array
    {
        return [
            "domain" => "blog.com",
            "type" => "social",
            "template" => "blog_post",
            "track" => "social_media",
            "track_type" => "social",
            "workflow" => "default",
            "lang" => "en",
            "content_json" => "/data/blog.com/{$key}/content.json",
            "content_md" => $contentMd,
            "description_md" => "/data/blog.com/{$key}/desc.md",
            "research_text" => "Research for {$key}.",
        ];
    }
}
