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
use App\PncServices\Contracts\ContentPersistenceInterface;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit tests for {@see JsonWorkflowExecutor}.
 *
 * Tests the orchestration layer — how the executor loads definitions,
 * dispatches stages (linear and loop), applies transforms, persists output,
 * and returns the final result.
 *
 * Delegated concerns tested in their own test classes:
 *  - {@see WorkflowDefinitionLoaderTest} — file resolution, JSON decoding,
 *    3-tier resolution order, default.json fallback.
 *  - {@see LoopStageExecutorTest} — iteration, per-iteration reset,
 *    accumulation, JSON string source decoding.
 *  - {@see TemplateResolverTest} — {{key}} interpolation, type preservation,
 *    array/object JSON-encoding.
 *  - {@see StageTransformTest} — strip_code_fence, json_decode, unknown passthrough.
 *  - {@see WorkflowPersisterTest} — path/filename resolution, output_key
 *    fallback, content type handling, call ordering.
 *
 * File I/O is directed to a temporary directory. SkillExecutorInterface and
 * ContentPersistenceInterface are mocked via Mockery.
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
     * Mock content persistence service (handles disk writes).
     */
    private ContentPersistenceInterface $persistence;

    /**
     * Temporary directory acting as the default definitions dir.
     */
    private string $definitionsDir;

    /**
     * The system under test.
     */
    private JsonWorkflowExecutor $sut;

    /**
     * Set up temp directory, mock interfaces, instantiate the executor.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->definitionsDir =
            sys_get_temp_dir() . "/wf_definitions_" . uniqid();
        mkdir($this->definitionsDir, 0755, true);

        $this->executor = Mockery::mock(SkillExecutorInterface::class);
        $this->persistence = Mockery::mock(ContentPersistenceInterface::class);

        $this->sut = new JsonWorkflowExecutor(
            $this->executor,
            $this->persistence,
            $this->definitionsDir,
        );

        $this->unsetEnv("OVERLAY_WORKFLOWS");
        config()->set("pnc.workflows.override_dir", null);
    }

    /**
     * Clean up temp directory, reset env/config, close Mockery.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->definitionsDir);
        $this->unsetEnv("OVERLAY_WORKFLOWS");
        config()->set("pnc.workflows.override_dir", null);
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — linear stages
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A single linear stage should call the executor with resolved variables
     * and options, store the result under output_key, and return it as the
     * workflow's final output.
     */
    #[Test]
    public function execute_singleLinearStageReturnsOutput(): void
    {
        $this->writeWorkflow("simple", [
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
                ["title" => "My Article"],
                ["temperature" => 0.2],
            )
            ->andReturn("Generated outline");

        $result = $this->sut->execute("simple", ["title" => "My Article"]);

        $this->assertSame("Generated outline", $result);
    }

    /**
     * Multiple linear stages should pass context forward — the output_key
     * of one stage is available as a {{key}} variable in the next stage's
     * variables map.
     */
    #[Test]
    public function execute_chainedStagesPassContextForward(): void
    {
        $this->writeWorkflow("chained", [
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
            ->with("stage_one", ["input" => "Hello"], [])
            ->andReturn("World");

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("stage_two", ["input" => "World"], [])
            ->andReturn("Done");

        $result = $this->sut->execute("chained", ["title" => "Hello"]);

        $this->assertSame("Done", $result);
    }

    /**
     * The "skill" field should support {{key}} interpolation, allowing
     * dynamic skill resolution from context (e.g. track-specific skills).
     */
    #[Test]
    public function execute_resolvesDynamicSkillKeyFromContext(): void
    {
        $this->writeWorkflow("dynamic_skill", [
            "stages" => [
                [
                    "skill" => "{{track_key}}.core_writing.hook_writer",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "result",
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("developer_docs.core_writing.hook_writer", [], [])
            ->andReturn("Hook content");

        $result = $this->sut->execute("dynamic_skill", [
            "track_key" => "developer_docs",
        ]);

        $this->assertSame("Hook content", $result);
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — loop stages
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A loop stage should iterate over the source array, execute sub-stages
     * for each item, and accumulate results into the configured key.
     * Since the last stage is a loop (no top-level output_key), the return
     * value comes from the accumulate key.
     */
    #[Test]
    public function execute_loopStageIteratesAndAccumulates(): void
    {
        $this->writeWorkflow("loop_test", [
            "stages" => [
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

        $this->executor
            ->shouldReceive("execute")
            ->twice()
            ->ordered()
            ->andReturnUsing(
                fn(
                    string $skill,
                    array $vars,
                ) => "Content for {$vars["section"]}",
            );

        $result = $this->sut->execute("loop_test", [
            "sections" => ["Intro", "Conclusion"],
        ]);

        $this->assertSame(
            "## Intro\nContent for Intro## Conclusion\nContent for Conclusion",
            $result,
        );
    }

    /**
     * A loop stage whose source is a JSON string (produced by a prior
     * linear stage) should decode it into an array before iterating.
     */
    #[Test]
    public function execute_loopDecodesJsonStringSource(): void
    {
        $this->writeWorkflow("json_loop", [
            "stages" => [
                [
                    "id" => "get_sections",
                    "skill" => "section_generator",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "raw_sections",
                ],
                [
                    "id" => "loop",
                    "type" => "loop",
                    "source" => "{{raw_sections}}",
                    "item_key" => "section",
                    "stages" => [
                        [
                            "skill" => "writer",
                            "variables" => ["section" => "{{section}}"],
                            "options" => [],
                            "output_key" => "out",
                        ],
                    ],
                    "accumulate" => [
                        "key" => "body",
                        "template" => "{{section}}: {{out}}",
                    ],
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("section_generator", [], [])
            ->andReturn('["Alpha", "Beta"]');

        $this->executor
            ->shouldReceive("execute")
            ->twice()
            ->andReturnUsing(
                fn(string $skill, array $vars) => "Wrote {$vars["section"]}",
            );

        $result = $this->sut->execute("json_loop", []);

        $this->assertSame("Alpha: Wrote AlphaBeta: Wrote Beta", $result);
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — transforms (integration-level)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A stage with "transform": "strip_code_fence" should have the transform
     * applied to the executor's output before storing in context. The final
     * return value reflects the transformed (fence-stripped) string.
     */
    #[Test]
    public function execute_appliesStripCodeFenceTransform(): void
    {
        $this->writeWorkflow("transform_test", [
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

        $result = $this->sut->execute("transform_test", []);

        $this->assertSame('["a", "b"]', $result);
    }

    /**
     * A stage with "transform": "json_decode" should decode the output into
     * a PHP array. A subsequent stage referencing {{decoded}} should receive
     * the JSON-encoded string (TemplateResolver serialises arrays to JSON).
     */
    #[Test]
    public function execute_appliesJsonDecodeTransform(): void
    {
        $this->writeWorkflow("json_decode_test", [
            "stages" => [
                [
                    "skill" => "json_generator",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "decoded",
                    "transform" => "json_decode",
                ],
                [
                    "skill" => "formatter",
                    "variables" => ["data" => "{{decoded}}"],
                    "options" => [],
                    "output_key" => "result",
                ],
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("json_generator", [], [])
            ->andReturn('{"key": "value"}');

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("formatter", ["data" => '{"key":"value"}'], [])
            ->andReturn('{"key":"value"}');

        $result = $this->sut->execute("json_decode_test", []);

        $this->assertSame('{"key":"value"}', $result);
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — persistence
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When a "persist" block is defined, the persistence service should be
     * called with resolved path, filename, and content read from the
     * output_key (defaults to "final_output").
     */
    #[Test]
    public function execute_persistsFinalOutputWhenConfigured(): void
    {
        $this->writeWorkflow("persist_test", [
            "stages" => [
                [
                    "skill" => "final_writer",
                    "variables" => ["content" => "{{title}}"],
                    "options" => [],
                    "output_key" => "final_output",
                ],
            ],
            "persist" => [
                "path" => "{{domain_dir}}",
                "filename" => "{{title_slug}}.md",
            ],
        ]);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->andReturn("Final markdown content");

        $this->persistence
            ->shouldReceive("setPath")
            ->once()
            ->with("/output/articles");

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("Final markdown content", "my-article.md")
            ->andReturn("");

        $this->sut->execute("persist_test", [
            "title" => "My Article",
            "title_slug" => "my-article",
            "domain_dir" => "/output/articles",
        ]);
    }

    /**
     * When no "persist" block is defined, the persistence service should
     * not be called at all.
     */
    #[Test]
    public function execute_doesNotPersistWhenNoPersistBlock(): void
    {
        $this->writeWorkflow("no_persist", [
            "stages" => [
                [
                    "skill" => "writer",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "result",
                ],
            ],
        ]);

        $this->executor->shouldReceive("execute")->once()->andReturn("Output");

        $this->persistence->shouldNotReceive("setPath");
        $this->persistence->shouldNotReceive("saveMarkdown");

        $this->sut->execute("no_persist", []);
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() — definition loading (integration with WorkflowDefinitionLoader)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When OVERLAY_WORKFLOWS is set and the file exists there, the overlay
     * version should be used (highest priority over the default directory).
     */
    #[Test]
    public function execute_prefersOverlayDefinitionOverDefault(): void
    {
        $overlayWorkflow = [
            "stages" => [
                [
                    "skill" => "overlay_skill",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "out",
                ],
            ],
        ];
        $defaultWorkflow = [
            "stages" => [
                [
                    "skill" => "default_skill",
                    "variables" => [],
                    "options" => [],
                    "output_key" => "out",
                ],
            ],
        ];

        $relativeOverlay = "storage/test_wf_overlay_" . uniqid();
        $absoluteOverlay = base_path($relativeOverlay);
        mkdir($absoluteOverlay, 0755, true);

        file_put_contents(
            $absoluteOverlay . "/my_wf.json",
            json_encode($overlayWorkflow),
        );
        file_put_contents(
            $this->definitionsDir . "/my_wf.json",
            json_encode($defaultWorkflow),
        );

        $this->setEnv("OVERLAY_WORKFLOWS", $relativeOverlay);

        $this->executor
            ->shouldReceive("execute")
            ->once()
            ->with("overlay_skill", [], [])
            ->andReturn("from overlay");

        $result = $this->sut->execute("my_wf", []);
        $this->assertSame("from overlay", $result);

        $this->removeDirectory($absoluteOverlay);
    }

    /**
     * When the workflow file is not found in any directory and no
     * default.json exists, a RuntimeException should be thrown with a
     * message listing all searched paths.
     */
    #[Test]
    public function execute_throwsWhenDefinitionNotFoundAnywhere(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Workflow definition not found");

        $this->sut->execute("nonexistent_workflow", []);
    }

    /**
     * When the workflow file contains invalid JSON, a RuntimeException
     * should be thrown with the JSON error message.
     */
    #[Test]
    public function execute_throwsOnInvalidJson(): void
    {
        file_put_contents(
            $this->definitionsDir . "/broken.json",
            "{invalid json here",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid JSON");

        $this->sut->execute("broken", []);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Write a workflow definition JSON file to the default definitions dir.
     *
     * @param string $name Workflow name (filename without .json).
     * @param array  $data Workflow definition array.
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

    /**
     * Set an environment variable in all locations Laravel's env() reads from
     * ($_ENV, $_SERVER, and the process environment).
     */
    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    /**
     * Unset an environment variable from all locations.
     */
    private function unsetEnv(string $key): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }
}
