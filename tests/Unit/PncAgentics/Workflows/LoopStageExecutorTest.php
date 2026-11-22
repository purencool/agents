<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Workflows;

use App\PncAgentics\Contracts\LoopStageExecutorContractInterface;
use App\PncAgentics\Contracts\LoopStageResolverContractInterface;
use App\PncAgentics\Workflows\LoopStageExecutor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see LoopStageExecutor}.
 *
 * Covers:
 *  - Iteration over a source array from context.
 *  - JSON string source decoding.
 *  - Non-array source (null, scalar) results in zero iterations.
 *  - Per-iteration output key reset.
 *  - Accumulation into a running string.
 *  - No accumulation when "accumulate" is not configured.
 *  - Multiple sub-stages per iteration.
 *
 * The executor and resolver contracts are mocked via Mockery — no file I/O
 * or LLM calls are made.
 *
 * @covers \App\PncAgentics\Workflows\LoopStageExecutor
 */
class LoopStageExecutorTest extends TestCase
{
    /**
     * Mock stage executor.
     */
    private LoopStageExecutorContractInterface $executor;

    /**
     * Mock variable/template resolver.
     */
    private LoopStageResolverContractInterface $resolver;

    /**
     * The system under test.
     */
    private LoopStageExecutor $sut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = \Mockery::mock(
            LoopStageExecutorContractInterface::class,
        );
        $this->resolver = \Mockery::mock(
            LoopStageResolverContractInterface::class,
        );
        $this->sut = new LoopStageExecutor($this->executor, $this->resolver);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Basic iteration
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Should iterate over the source array, inject each item under item_key,
     * and execute sub-stages once per item.
     */
    #[Test]
    public function execute_iteratesOverSourceAndExecutesSubStagesPerItem(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{sections}}",
            "item_key" => "section",
            "stages" => [["skill" => "writer", "output_key" => "output"]],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->with("{{sections}}", \Mockery::type("array"))
            ->andReturn(["Intro", "Body", "Conclusion"]);

        $this->executor
            ->shouldReceive("executeStage")
            ->times(3)
            ->ordered()
            ->withArgs(function (array $subStage, array &$context): bool {
                return $subStage["skill"] === "writer" &&
                    isset($context["section"]);
            });

        $context = ["sections" => ["Intro", "Body", "Conclusion"]];
        $this->sut->execute($stage, $context);

        // Last item remains in context after the loop.
        $this->assertSame("Conclusion", $context["section"]);
    }

    /**
     * The item_key value should be updated on each iteration.
     */
    #[Test]
    public function execute_updatesItemKeyOnEachIteration(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{items}}",
            "item_key" => "current",
            "stages" => [["skill" => "proc", "output_key" => "out"]],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->andReturn(["A", "B", "C"]);

        $seenItems = [];
        $this->executor
            ->shouldReceive("executeStage")
            ->times(3)
            ->ordered()
            ->withArgs(function (array $subStage, array &$context) use (
                &$seenItems,
            ): bool {
                $seenItems[] = $context["current"];
                return true;
            });

        $context = ["items" => ["A", "B", "C"]];
        $this->sut->execute($stage, $context);

        $this->assertSame(["A", "B", "C"], $seenItems);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Source resolution
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the source resolves to a JSON string, it should be decoded
     * into an array before iteration.
     */
    #[Test]
    public function execute_decodesJsonStringSource(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{raw}}",
            "item_key" => "item",
            "stages" => [["skill" => "proc", "output_key" => "out"]],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->andReturn('["Alpha", "Beta"]');

        $this->executor->shouldReceive("executeStage")->twice();

        $context = ["raw" => '["Alpha", "Beta"]'];
        $this->sut->execute($stage, $context);

        $this->assertSame("Beta", $context["item"]);
    }

    /**
     * When the source resolves to null (missing key), the loop should
     * execute zero iterations.
     */
    #[Test]
    public function execute_noIterationsWhenSourceIsNull(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{missing}}",
            "item_key" => "item",
            "stages" => [["skill" => "proc", "output_key" => "out"]],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->andReturn(null);

        $this->executor->shouldNotReceive("executeStage");

        $context = [];
        $this->sut->execute($stage, $context);

        $this->assertArrayNotHasKey("item", $context);
    }

    /**
     * When the source resolves to a scalar (not array, not JSON), the loop
     * should execute zero iterations.
     */
    #[Test]
    public function execute_noIterationsWhenSourceIsScalar(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{scalar}}",
            "item_key" => "item",
            "stages" => [["skill" => "proc", "output_key" => "out"]],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->andReturn("just a string");

        $this->executor->shouldNotReceive("executeStage");

        $context = ["scalar" => "just a string"];
        $this->sut->execute($stage, $context);

        $this->assertArrayNotHasKey("item", $context);
    }

    /**
     * An empty array source should result in zero iterations.
     */
    #[Test]
    public function execute_noIterationsWhenSourceIsEmptyArray(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{empty}}",
            "item_key" => "item",
            "stages" => [["skill" => "proc", "output_key" => "out"]],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->andReturn([]);

        $this->executor->shouldNotReceive("executeStage");

        $context = ["empty" => []];
        $this->sut->execute($stage, $context);

        $this->assertArrayNotHasKey("item", $context);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Per-iteration output key reset
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Sub-stage output keys should be reset to "" before each iteration's
     * sub-stages execute, preventing stale values from leaking between iterations.
     */
    #[Test]
    public function execute_resetsOutputKeysBeforeEachIteration(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{items}}",
            "item_key" => "item",
            "stages" => [["skill" => "writer", "output_key" => "result"]],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->andReturn(["A", "B"]);

        $this->executor
            ->shouldReceive("executeStage")
            ->twice()
            ->ordered()
            ->withArgs(function (array $subStage, array &$context): bool {
                // On each call, "result" must be "" (reset before execution).
                $this->assertSame("", $context["result"]);
                // Simulate the stage writing output.
                $context["result"] = "output_for_{$context["item"]}";
                return true;
            });

        $context = ["items" => ["A", "B"]];
        $this->sut->execute($stage, $context);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Accumulation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When "accumulate" is configured, the template should be resolved after
     * each iteration and appended to the accumulator string in context.
     */
    #[Test]
    public function execute_accumulatesResultsIntoRunningString(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{items}}",
            "item_key" => "item",
            "stages" => [["skill" => "writer", "output_key" => "result"]],
            "accumulate" => [
                "key" => "body",
                "template" => "{{item}}: {{result}}",
            ],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->andReturn(["X", "Y"]);

        $this->executor
            ->shouldReceive("executeStage")
            ->twice()
            ->ordered()
            ->withArgs(function (array $subStage, array &$context): bool {
                $context["result"] = "done_" . $context["item"];
                return true;
            });

        $this->resolver
            ->shouldReceive("resolveTemplate")
            ->twice()
            ->ordered()
            ->andReturn("X: done_X", "Y: done_Y");

        $context = ["items" => ["X", "Y"]];
        $this->sut->execute($stage, $context);

        $this->assertSame("X: done_XY: done_Y", $context["body"]);
    }

    /**
     * The accumulator should be initialised to "" before the first iteration.
     */
    #[Test]
    public function execute_initialisesAccumulatorToEmptyString(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{items}}",
            "item_key" => "item",
            "stages" => [["skill" => "writer", "output_key" => "result"]],
            "accumulate" => [
                "key" => "body",
                "template" => "{{item}}",
            ],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->andReturn(["A"]);

        $this->executor
            ->shouldReceive("executeStage")
            ->once()
            ->withArgs(function (array $subStage, array &$context): bool {
                // At this point "body" should already be "" (initialised).
                $this->assertSame("", $context["body"]);
                $context["result"] = "done";
                return true;
            });

        $this->resolver
            ->shouldReceive("resolveTemplate")
            ->once()
            ->andReturn("A");

        $context = ["items" => ["A"]];
        $this->sut->execute($stage, $context);

        $this->assertSame("A", $context["body"]);
    }

    /**
     * When no "accumulate" config is present, no accumulator key should be
     * created and resolveTemplate should not be called for accumulation.
     */
    #[Test]
    public function execute_noAccumulationWhenNotConfigured(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{items}}",
            "item_key" => "item",
            "stages" => [["skill" => "writer", "output_key" => "result"]],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->andReturn(["A", "B"]);

        $this->executor->shouldReceive("executeStage")->twice();

        $this->resolver->shouldNotReceive("resolveTemplate");

        $context = ["items" => ["A", "B"]];
        $this->sut->execute($stage, $context);

        $this->assertArrayNotHasKey("body", $context);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Multiple sub-stages
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When a loop stage has multiple sub-stages, all should execute in order
     * for each item.
     */
    #[Test]
    public function execute_runsMultipleSubStagesInOrderPerItem(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{items}}",
            "item_key" => "item",
            "stages" => [
                ["skill" => "outline", "output_key" => "outline_result"],
                ["skill" => "draft", "output_key" => "draft_result"],
            ],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->andReturn(["S1"]);

        $callOrder = [];
        $this->executor
            ->shouldReceive("executeStage")
            ->twice()
            ->ordered()
            ->withArgs(function (array $subStage, array &$context) use (
                &$callOrder,
            ): bool {
                $callOrder[] = $subStage["skill"];
                $context[$subStage["output_key"]] = "ok";
                return true;
            });

        $context = ["items" => ["S1"]];
        $this->sut->execute($stage, $context);

        $this->assertSame(["outline", "draft"], $callOrder);
    }

    /**
     * With multiple sub-stages, ALL output keys should be reset before
     * each iteration.
     */
    #[Test]
    public function execute_resetsAllSubStageOutputKeys(): void
    {
        $stage = [
            "type" => "loop",
            "source" => "{{items}}",
            "item_key" => "item",
            "stages" => [
                ["skill" => "a", "output_key" => "out_a"],
                ["skill" => "b", "output_key" => "out_b"],
            ],
        ];

        $this->resolver
            ->shouldReceive("resolveVariable")
            ->once()
            ->andReturn(["X"]);

        $this->executor
            ->shouldReceive("executeStage")
            ->twice()
            ->ordered()
            ->withArgs(function (array $subStage, array &$context): bool {
                // Only the current sub-stage's key must be "" (it was reset before this iteration).
                $this->assertSame("", $context[$subStage["output_key"]]);
                $context[$subStage["output_key"]] = "set";
                return true;
            });

        $context = ["items" => ["X"]];
        $this->sut->execute($stage, $context);
    }
}
