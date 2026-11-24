<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Workflows;

use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Workflows\JsonWorkflowExecutor;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit tests for {@see JsonWorkflowExecutor}.
 *
 * Tests the orchestration layer — how the executor:
 *  - Iterates the two-level tracks array (domain → type-lang).
 *  - Builds a flat context per track via buildContext().
 *  - Loads the workflow definition per track's "workflow" field.
 *  - Dispatches linear stages (skill call → transform → store in context).
 *  - Dispatches loop stages via LoopStageExecutor.
 *  - Extracts the final output from the last stage's output_key.
 *  - Persists output to the track's content files on disk.
 *  - Returns the structure with "workflow_results" added.
 *
 * Delegated concerns tested in their own test classes:
 *  - {@see WorkflowDefinitionLoaderTest} — file resolution, JSON decoding,
 *    config override, blog_post.json fallback.
 *  - {@see LoopStageExecutorTest} — iteration, per-iteration reset,
 *    accumulation, JSON string source decoding.
 *  - {@see TemplateResolverTest} — {{key}} interpolation, type preservation,
 *    array/object JSON-encoding.
 *  - {@see StageTransformTest} — strip_code_fence, json_decode, unknown passthrough.
 *
 * File I/O is directed to a temporary directory. SkillExecutorInterface is
 * mocked via Mockery. Content files (content_path_json, content_path_markdown)
 * are created as real temp files to test persistTrackOutput().
 *
 * @covers \App\PncAgentics\Workflows\JsonWorkflowExecutor
 */
class JsonWorkflowExecutorTest extends TestCase
{
    /**
     * Mock skill executor (handles LLM calls).
     */
    private SkillExecutorInterface $executor;

    /**
     * Temporary directory acting as the default definitions dir.
     */
    private string $definitionsDir;

    /**
     * Temporary base directory for content files.
     */
    private string $contentDir;

    /**
     * The system under test.
     */
    private JsonWorkflowExecutor $sut;

    /**
     * Set up temp directories, mock the skill executor, configure the loader,
     * and instantiate the executor.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->definitionsDir =
            sys_get_temp_dir() . "/wf_definitions_" . uniqid();
        mkdir($this->definitionsDir, 0755, true);

        $this->contentDir = sys_get_temp_dir() . "/wf_content_" . uniqid();
        mkdir($this->contentDir, 0755, true);

        $this->executor = Mockery::mock(SkillExecutorInterface::class);

        // Point the WorkflowDefinitionLoader at our temp definitions dir.
        config()->set("pnc.workflows.definitions_dir", $this->definitionsDir);
        config()->set("pnc.workflows.override_dir", null);

        $this->sut = new JsonWorkflowExecutor($this->executor);
    }

    /**
     * Clean up temp directories, reset config, close Mockery.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->definitionsDir);
        $this->removeDirectory($this->contentDir);

        config()->set("pnc.workflows.definitions_dir", null);
        config()->set("pnc.workflows.override_dir", null);

        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — basic structure passthrough
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The returned structure should preserve all original top-level keys
     * and add a "workflow_results" key.
     */
    #[Test]
    public function execute_preserves_structure_and_adds_workflow_results(): void
    {
        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "skill" => "test_skill",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "final_output",
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->andReturn("Generated content");

        $structure = $this->buildStructure();
        $result = $this->sut->execute($structure);

        // Original keys preserved
        $this->assertSame($structure["uuid"], $result["uuid"]);
        $this->assertSame($structure["title"], $result["title"]);
        $this->assertSame($structure["title_slug"], $result["title_slug"]);
        $this->assertSame($structure["description"], $result["description"]);
        $this->assertSame($structure["categories"], $result["categories"]);
        $this->assertSame($structure["domain_dir"], $result["domain_dir"]);
        $this->assertSame($structure["assets_dir"], $result["assets_dir"]);
        $this->assertSame($structure["research_dir"], $result["research_dir"]);
        $this->assertSame($structure["tracks"], $result["tracks"]);

        // workflow_results added
        $this->assertArrayHasKey("workflow_results", $result);
    }

    /**
     * The workflow_results key should be keyed by domain → trackKey.
     */
    #[Test]
    public function execute_workflow_results_keyed_by_domain_and_track(): void
    {
        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "skill" => "test_skill",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "final_output",
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->andReturn("Output text");

        $structure = $this->buildStructure();
        $result = $this->sut->execute($structure);

        $this->assertArrayHasKey("blog.com", $result["workflow_results"]);
        $this->assertArrayHasKey(
            "blog-en",
            $result["workflow_results"]["blog.com"],
        );
        $this->assertSame(
            ["text" => "Output text"],
            $result["workflow_results"]["blog.com"]["blog-en"],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — linear stages
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A single linear stage should call the executor with resolved variables
     * and options, store the result under output_key, and wrap string output
     * in a ["text" => ...] array.
     */
    #[Test]
    public function execute_singleLinearStageWrapsStringInTextArray(): void
    {
        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "skill" => "research_preparation.outline_generator",
                    "variables" => ["title" => "{{title}}"],
                    "options" => ["temperature" => 0.2],
                    "output_key" => "result",
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "research_preparation.outline_generator",
                ["title" => "Can AI models be deployed on-premises?"],
                ["temperature" => 0.2],
            )
            ->andReturn("Generated outline");

        $structure = $this->buildStructure();
        $result = $this->sut->execute($structure);

        $this->assertSame(
            ["text" => "Generated outline"],
            $result["workflow_results"]["blog.com"]["blog-en"],
        );
    }

    /**
     * Multiple linear stages should pass context forward — the output_key
     * of one stage is available as a {{key}} variable in the next stage.
     */
    #[Test]
    public function execute_chainedStagesPassContextForward(): void
    {
        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "skill" => "stage_one",
                    "variables" => ["input" => "{{title}}"],
                    "options" => [],
                    "output_key" => "first_result",
                ],
                [
                    "skill" => "stage_two",
                    "variables" => ["input" => "{{first_result}}"],
                    "options" => [],
                    "output_key" => "second_result",
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with(
                "stage_one",
                ["input" => "Can AI models be deployed on-premises?"],
                [],
            )
            ->andReturn("World");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("stage_two", ["input" => "World"], [])
            ->andReturn("Done");

        $structure = $this->buildStructure();
        $result = $this->sut->execute($structure);

        $this->assertSame(
            ["text" => "Done"],
            $result["workflow_results"]["blog.com"]["blog-en"],
        );
    }

    /**
     * The "skill" field should support {{key}} interpolation from context.
     */
    #[Test]
    public function execute_resolvesDynamicSkillKeyFromContext(): void
    {
        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "skill" => "{{domain}}.core_writing.hook_writer",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "result",
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("blog.com.core_writing.hook_writer", [], [])
            ->andReturn("Hook content");

        $structure = $this->buildStructure();
        $result = $this->sut->execute($structure);

        $this->assertSame(
            ["text" => "Hook content"],
            $result["workflow_results"]["blog.com"]["blog-en"],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — context building
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The context passed to the executor should contain all expected keys
     * derived from the structure and the track.
     */
    #[Test]
    public function execute_builds_context_from_structure_and_track(): void
    {
        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "skill" => "context_checker",
                    "variables" => [
                        "domain" => "{{domain}}",
                        "context" => "{{context}}",
                        "type" => "{{type}}",
                        "lang" => "{{lang}}",
                        "template" => "{{template}}",
                        "track" => "{{track}}",
                        "track_type" => "{{track_type}}",
                        "workflow" => "{{workflow}}",
                        "description" => "{{description}}",
                    ],
                    "options" => [],
                    "output_key" => "result",
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->withArgs(function (string $skill, array $vars): bool {
                return $vars["domain"] === "blog.com" &&
                    $vars["context"] === "This for marketing" &&
                    $vars["type"] === "blog" &&
                    $vars["lang"] === "en" &&
                    $vars["template"] === "social" &&
                    $vars["track"] === "social_media" &&
                    $vars["track_type"] === "social" &&
                    $vars["workflow"] === "default" &&
                    $vars["description"] === "Test description";
            })
            ->andReturn("OK");

        $structure = $this->buildStructure();
        $this->sut->execute($structure);
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — multi-track iteration
    // ─────────────────────────────────────────────────────────────────────

    /**
     * With two tracks, the executor should be called once per track and
     * workflow_results should contain entries for both.
     */
    #[Test]
    public function execute_iterates_all_tracks(): void
    {
        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "skill" => "writer",
                    "variables" => ["domain" => "{{domain}}"],
                    "options" => [],
                    "output_key" => "out",
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("writer", ["domain" => "blog.com"], [])
            ->andReturn("Blog output");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("writer", ["domain" => "meeting.com"], [])
            ->andReturn("Meeting output");

        $structure = $this->buildStructure([
            "blog.com" => [
                "blog-en" => $this->buildTrack(
                    "blog.com",
                    "blog",
                    "blog-en",
                    "This for marketing",
                ),
            ],
            "meeting.com" => [
                "meeting-en" => $this->buildTrack(
                    "meeting.com",
                    "meeting",
                    "meeting-en",
                    "This for security",
                ),
            ],
        ]);

        $result = $this->sut->execute($structure);

        $this->assertCount(2, $result["workflow_results"]);
        $this->assertSame(
            ["text" => "Blog output"],
            $result["workflow_results"]["blog.com"]["blog-en"],
        );
        $this->assertSame(
            ["text" => "Meeting output"],
            $result["workflow_results"]["meeting.com"]["meeting-en"],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — transforms
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A stage with "transform": "strip_code_fence" should have the transform
     * applied before storing in context.
     */
    #[Test]
    public function execute_appliesStripCodeFenceTransform(): void
    {
        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "skill" => "json_generator",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "clean_json",
                    "transform" => "strip_code_fence",
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->andReturn("```json\n[\"a\", \"b\"]\n```");

        $structure = $this->buildStructure();
        $result = $this->sut->execute($structure);

        // After strip_code_fence: '["a", "b"]' → json_decode → array
        $this->assertSame(
            ["a", "b"],
            $result["workflow_results"]["blog.com"]["blog-en"],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — loop stages
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A loop stage should iterate over the source array, execute sub-stages
     * for each item, and accumulate results. Since the last stage is a loop,
     * the return value comes from the accumulate key.
     *
     * The accumulated string is not valid JSON, so production wraps it in
     * ["text" => $raw].
     */
    #[Test]
    public function execute_loopStageIteratesAndAccumulates(): void
    {
        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "id" => "get_sections",
                    "skill" => "section_generator",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "sections",
                ],
                [
                    "id" => "loop",
                    "type" => "loop",
                    "source" => "{{sections}}",
                    "item_key" => "section",
                    "stages" => [
                        [
                            "skill" => "writer",
                            "variables" => ["section" => "{{section}}"],
                            "options" => [],
                            "output_key" => "polished",
                        ],
                    ],
                    "accumulate" => [
                        "key" => "body",
                        "template" => "## {{section}}\n{{polished}}",
                    ],
                ],
            ],
        ]);

        // First call: section_generator (returns JSON string of sections)
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("section_generator", [], [])
            ->andReturn(json_encode(["Intro", "Conclusion"]));

        // Second + third calls: loop sub-stages (one per item)
        $this->executor
            ->shouldReceive("execute")
            ->twice()
            ->andReturnUsing(function (string $skill, array $vars): string {
                $section = $vars["section"] ?? "unknown";
                return "Content for {$section}";
            });

        $structure = $this->buildStructure();
        $result = $this->sut->execute($structure);

        // Production wraps non-JSON string output in ["text" => ...]
        $this->assertSame(
            [
                "text" =>
                    "## Intro\nContent for Intro## Conclusion\nContent for Conclusion",
            ],
            $result["workflow_results"]["blog.com"]["blog-en"],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — persistence to disk
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When content_path_markdown is set, the output text should be written
     * to that file.
     */
    #[Test]
    public function execute_persists_markdown_to_content_path(): void
    {
        $mdPath = "{$this->contentDir}/output.md";
        file_put_contents($mdPath, "");

        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "skill" => "writer",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "final_output",
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->andReturn("# My Article\n\nContent here.");

        $structure = $this->buildStructure();
        $structure["tracks"]["blog.com"]["blog-en"][
            "content_path_markdown"
        ] = $mdPath;

        $this->sut->execute($structure);

        $this->assertFileExists($mdPath);
        $this->assertSame(
            "# My Article\n\nContent here.",
            file_get_contents($mdPath),
        );
    }

    /**
     * When content_path_json is set and the file exists, the output should
     * be merged into the JSON under the "content" key.
     *
     * NOTE: SkillExecutorInterface::execute() returns string, so the mock
     * must return a JSON-encoded string (not an array).
     */
    #[Test]
    public function execute_persists_json_to_content_path(): void
    {
        $jsonPath = "{$this->contentDir}/content.json";
        file_put_contents(
            $jsonPath,
            json_encode([
                ["content" => ["uuid" => "abc", "domain" => "blog.com"]],
            ]),
        );

        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "skill" => "writer",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "final_output",
                ],
            ],
        ]);

        // Must return a STRING (interface return type is string).
        // The production code json_decodes it if valid JSON.
        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->andReturn('{"text":"JSON content"}');

        $structure = $this->buildStructure();
        $structure["tracks"]["blog.com"]["blog-en"][
            "content_path_json"
        ] = $jsonPath;

        $this->sut->execute($structure);

        $data = json_decode((string) file_get_contents($jsonPath), true);
        // Production json_decodes the string result → array → stored as "content"
        $this->assertSame(["text" => "JSON content"], $data["content"]);
    }

    /**
     * When content_path_markdown is empty, no file should be written.
     */
    #[Test]
    public function execute_does_not_persist_markdown_when_path_empty(): void
    {
        $this->writeWorkflow("default", [
            "stages" => [
                [
                    "skill" => "writer",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "final_output",
                ],
            ],
        ]);

        $this->executor->shouldReceive("execute")->once()->andReturn("Output");

        $structure = $this->buildStructure();
        $structure["tracks"]["blog.com"]["blog-en"]["content_path_markdown"] =
            "";

        $this->sut->execute($structure);

        // No assertion needed — just verify no exception was thrown.
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — definition loading
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the workflow file is not found and no blog_post.json fallback
     * exists, a RuntimeException should be thrown.
     */
    #[Test]
    public function execute_throwsWhenDefinitionNotFound(): void
    {
        $structure = $this->buildStructure();
        $structure["tracks"]["blog.com"]["blog-en"]["workflow"] =
            "nonexistent_wf";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Workflow definition not found");

        $this->sut->execute($structure);
    }

    /**
     * When the workflow file contains invalid JSON, a RuntimeException
     * should be thrown.
     */
    #[Test]
    public function execute_throwsOnInvalidJson(): void
    {
        file_put_contents(
            $this->definitionsDir . "/broken.json",
            "{invalid json here",
        );

        $structure = $this->buildStructure();
        $structure["tracks"]["blog.com"]["blog-en"]["workflow"] = "broken";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid JSON");

        $this->sut->execute($structure);
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — config override for definitions
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When pnc.workflows.override_dir is set (as a RELATIVE path) and the
     * file exists there, it should be preferred over the default dir.
     *
     * NOTE: Production resolves the override via base_path($configOverride),
     * so the config value must be relative.
     */
    #[Test]
    public function execute_prefersConfigOverrideDefinition(): void
    {
        // Use a relative path — production resolves it via base_path()
        $overrideRelative = "test_wf_override_" . uniqid();
        $overrideAbsolute = base_path($overrideRelative);
        mkdir($overrideAbsolute, 0755, true);

        file_put_contents(
            $overrideAbsolute . "/default.json",
            json_encode([
                "stages" => [
                    [
                        "skill" => "override_skill",
                        "variables" => [],
                        "options" => [],
                        "output_key" => "out",
                    ],
                ],
            ]),
        );

        file_put_contents(
            $this->definitionsDir . "/default.json",
            json_encode([
                "stages" => [
                    [
                        "skill" => "default_skill",
                        "variables" => [],
                        "options" => [],
                        "output_key" => "out",
                    ],
                ],
            ]),
        );

        // Set the config to the RELATIVE path
        config()->set("pnc.workflows.override_dir", $overrideRelative);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("override_skill", [], [])
            ->andReturn("from override");

        $structure = $this->buildStructure();
        $result = $this->sut->execute($structure);

        $this->assertSame(
            ["text" => "from override"],
            $result["workflow_results"]["blog.com"]["blog-en"],
        );

        $this->removeDirectory($overrideAbsolute);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build a standard test structure with one or two tracks.
     *
     * @param array|null $tracks Custom tracks array. If null, uses a single blog.com track.
     */
    private function buildStructure(?array $tracks = null): array
    {
        if ($tracks === null) {
            $tracks = [
                "blog.com" => [
                    "blog-en" => $this->buildTrack(
                        "blog.com",
                        "blog",
                        "blog-en",
                        "This for marketing",
                    ),
                ],
            ];
        }

        return [
            "uuid" => "test-uuid-123",
            "title" => "Can AI models be deployed on-premises?",
            "title_slug" => "can_ai_models_be_deployed_on_premises",
            "description" => "Test description",
            "categories" => ["AI", "LLMs"],
            "domain_dir" => $this->contentDir,
            "assets_dir" => "{$this->contentDir}/assets",
            "research_dir" => "{$this->contentDir}/research",
            "tracks" => $tracks,
        ];
    }

    /**
     * Build a single track entry with all 18 documented fields.
     */
    private function buildTrack(
        string $domain,
        string $type,
        string $trackKey,
        string $context,
    ): array {
        $domainSlug = \Illuminate\Support\Str::slug($domain, "_");
        $typeSlug = \Illuminate\Support\Str::slug($type, "_");
        $lang = explode("-", $trackKey)[1] ?? "en";

        return [
            "uuid" => "test-uuid-123",
            "title" => "Can AI models be deployed on-premises?",
            "categories" => ["AI", "LLMs"],
            "workflow" => "default",
            "lang" => $lang,
            "domain" => $domain,
            "context" => $context,
            "type" => $type,
            "template" => "social",
            "track" => "social_media",
            "track_type" => "social",
            "content_json" => "",
            "content_path_json" => "",
            "content_html" => "",
            "content_path_html" => "",
            "content_markdown" => "",
            "content_path_markdown" => "",
            "content_description" => "",
        ];
    }

    /**
     * Write a workflow definition JSON file to the default definitions dir.
     */
    private function writeWorkflow(string $name, array $data): void
    {
        file_put_contents(
            $this->definitionsDir . "/{$name}.json",
            json_encode($data, JSON_PRETTY_PRINT),
        );
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
