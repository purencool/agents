<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Workflows;

use App\PncAgentics\Contracts\LoopStageExecutorContractInterface;
use App\PncAgentics\Contracts\LoopStageResolverContractInterface;

/**
 * Executes a loop stage within a workflow definition.
 *
 * Iterates over a source array (resolved from context), executes sub-stages
 * for each item, and optionally accumulates results into a running string.
 *
 * The source can be:
 *  - A context variable containing an array directly.
 *  - A context variable containing a JSON string (decoded before iteration).
 *
 * Per-iteration behaviour:
 *  1. The current item is injected into context under "item_key".
 *  2. All sub-stage output keys are reset to "".
 *  3. Sub-stages are executed in order.
 *  4. If "accumulate" is configured, the template is resolved and appended
 *     to the accumulator string.
 *
 * @example
 *   $loop = new LoopStageExecutor($executor, $resolver);
 *   $loop->execute($stageConfig, $context);
 */
class LoopStageExecutor
{
    /**
     * @param \App\PncAgentics\Contracts\LoopStageExecutorContractInterface $executor  Executes individual sub-stages.
     * @param \App\PncAgentics\Contracts\LoopStageResolverContractInterface $resolver  Resolves variables and templates.
     */
    public function __construct(
        protected LoopStageExecutorContractInterface $executor,
        protected LoopStageResolverContractInterface $resolver,
    ) {}

    /**
     * Execute the loop stage, modifying the context in-place.
     *
     * @param array $stage   Loop stage definition.
     *                       Required keys: "type" (must be "loop"), "source",
     *                       "item_key", "stages" (array of sub-stage definitions).
     *                       Optional key: "accumulate" ({"key": string, "template": string}).
     * @param array $context Mutable context (modified in-place).
     */
    public function execute(array $stage, array &$context): void
    {
        $source = $this->resolver->resolveVariable($stage["source"], $context);

        // If source is a JSON string, decode it into an array.
        if (is_string($source)) {
            $source = json_decode($source, true) ?: [];
        }

        if (!is_array($source)) {
            $source = [];
        }

        $accumulator = $stage["accumulate"]["key"] ?? null;
        $accumulateTemplate = $stage["accumulate"]["template"] ?? "";

        if ($accumulator) {
            $context[$accumulator] = "";
        }

        foreach ($source as $item) {
            // Inject the current loop item into context.
            $context[$stage["item_key"]] = $item;

            // Reset per-iteration output keys so stale values don't leak.
            foreach ($stage["stages"] as $subStage) {
                if (isset($subStage["output_key"])) {
                    $context[$subStage["output_key"]] = "";
                }
            }

            // Execute sub-stages for this item.
            foreach ($stage["stages"] as $subStage) {
                $this->executor->executeStage($subStage, $context);
            }

            // Accumulate the result into the running string.
            if ($accumulator && $accumulateTemplate) {
                $context[$accumulator] .= $this->resolver->resolveTemplate(
                    $accumulateTemplate,
                    $context,
                );
            }
        }
    }
}
