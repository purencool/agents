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
use App\PncAgentics\Contracts\SkillExecutorInterface;

/**
 * Data-driven workflow executor.
 *
 * Orchestrates the execution of a JSON workflow definition by:
 *  - Loading the definition via {@see WorkflowDefinitionLoader}
 *    (override dir → default dir, with fallback to "default.json").
 *  - Dispatching linear stages directly (skill call → transform → store in context).
 *  - Dispatching loop stages via {@see LoopStageExecutor}
 *    (iteration, per-iteration reset, sub-stage execution, accumulation).
 *
 * Output persistence is handled externally (by the template/transformer
 * layer) — this class returns the result and does not write to disk.
 *
 * This class implements the contracts required by {@see LoopStageExecutor}:
 *  - {@see LoopStageExecutorContractInterface} — provides executeStage().
 *  - {@see LoopStageResolverContractInterface} — provides resolveVariable()
 *    and resolveTemplate() (delegated to {@see TemplateResolver}).
 *
 * Workflow JSON shape:
 *
 *   {
 *     "stages": [
 *       {
 *         "skill": "track.core_writing.outline_generator",
 *         "variables": { "title": "{{title}}" },
 *         "options": { "temperature": 0.2 },
 *         "output_key": "outline",
 *         "transform": "strip_code_fence"
 *       },
 *       {
 *         "type": "loop",
 *         "source": "{{sections}}",
 *         "item_key": "section",
 *         "stages": [ ... ],
 *         "accumulate": { "key": "body", "template": "## {{section}}\n{{output}}" }
 *       }
 *     ]
 *   }
 *
 * @example
 *   $executor = app(JsonWorkflowExecutor::class);
 *   $result = $executor->execute($structure);
 *   $output = $result["workflow_results"];
 */
class JsonWorkflowExecutor implements
    LoopStageExecutorContractInterface,
    LoopStageResolverContractInterface
{
    /**
     * Resolves and loads workflow definition JSON files from disk.
     */
    protected WorkflowDefinitionLoader $loader;

    /**
     * Executes loop stages (iteration, reset, sub-stage dispatch, accumulation).
     * Receives $this as both the stage executor and the resolver.
     */
    protected LoopStageExecutor $loopExecutor;

    /**
     * Resolves {{key}} placeholders from context arrays.
     */
    protected TemplateResolver $resolver;

    /**
     * Applies named output transforms (strip_code_fence, json_decode).
     */
    protected StageTransform $transform;

    /**
     * @param SkillExecutorInterface $executor The skill executor for LLM calls.
     */
    public function __construct(protected SkillExecutorInterface $executor)
    {
        $this->loader = new WorkflowDefinitionLoader();
        $this->resolver = new TemplateResolver();
        $this->transform = new StageTransform();
        $this->loopExecutor = new LoopStageExecutor($this, $this);
    }

    /**
     * Execute a workflow by name.
     *
     * Execution flow:
     *  1. Build a flat context from the structure.
     *  2. Load the workflow definition (delegated to WorkflowDefinitionLoader).
     *  3. Iterate through each stage in order:
     *     - Loop stages (type === "loop") → delegated to LoopStageExecutor.
     *     - Linear stages → executed via executeStage().
     *  4. Extract the final output and attach it to the structure.
     *
     * The original $structure is never mutated by stage execution —
     * a separate context array is used for the workflow's working state.
     *
     * @param array $structure The full content structure from upstream actions.
     *
     * @return array The same structure with "workflow_results" added.
     *
     * @throws \RuntimeException When the workflow definition file is not found or contains invalid JSON.
     */
    public function execute(array $structure): array
    {
        $context = $this->buildContext($structure);

        $definition = $this->loader->load($context["workflow"]);

        foreach ($definition["stages"] as $stage) {
            if (isset($stage["type"]) && $stage["type"] === "loop") {
                $this->loopExecutor->execute($stage, $context);
            } else {
                $this->executeStage($stage, $context);
            }
        }

        $lastStage = $definition["stages"][count($definition["stages"]) - 1];
        $outputKey =
            $lastStage["output_key"] ??
            ($lastStage["accumulate"]["key"] ?? null);

        $raw = $outputKey !== null ? $context[$outputKey] ?? "" : "";

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : ["text" => $raw];
        }

        return $raw;
    }

    /**
     * Execute a single linear stage.
     *
     * Execution flow:
     *  1. Resolve {{key}} variables from context (via TemplateResolver).
     *  2. Resolve the "skill" field (supports {{key}} interpolation).
     *  3. Call the skill executor with the resolved skill name, variables,
     *     and options.
     *  4. If a "transform" is defined, apply it to the result.
     *  5. Store the result in context under the stage's "output_key".
     *
     * Satisfies {@see LoopStageExecutorContractInterface::executeStage()}.
     *
     * @param array $stage   Stage definition from the workflow JSON.
     * @param array $context Mutable context (modified in-place).
     */
    public function executeStage(array $stage, array &$context): void
    {
        $variables = $this->resolver->resolveVariables(
            $stage["variables"] ?? [],
            $context,
        );
        $options = $stage["options"] ?? [];

        $result = $this->executor->execute(
            $this->resolver->resolveTemplate($stage["skill"], $context),
            $variables,
            $options,
        );

        if (!empty($stage["transform"])) {
            $result = $this->transform->apply($stage["transform"], $result);
        }

        $context[$stage["output_key"]] = $result;
    }

    /**
     * Resolve a single template string from context.
     *
     * Satisfies {@see LoopStageResolverContractInterface::resolveVariable()}.
     *
     * @param string $key     Template string with {{key}} placeholders.
     * @param array  $context Current context.
     *
     * @return mixed The resolved value.
     */
    public function resolveVariable(string $key, array $context): mixed
    {
        return $this->resolver->resolveVariable($key, $context);
    }

    /**
     * Replace all {{key}} placeholders in a template string with context values.
     *
     * Satisfies {@see LoopStageResolverContractInterface::resolveTemplate()}.
     *
     * @param string $template Template string.
     * @param array  $context  Current context.
     *
     * @return string The resolved template.
     */
    public function resolveTemplate(string $template, array $context): string
    {
        return $this->resolver->resolveTemplate($template, $context);
    }

    /**
     * Build a flat context array from the structure for workflow template resolution.
     *
     * The original $structure is never mutated — stage outputs are stored
     * in this separate array.
     *
     * @param array<string, mixed> $structure The full content structure.
     *
     * @return array<string, mixed> Flat context for stage execution.
     */
    protected function buildContext(array $structure): array
    {
        $track = $structure["tracks"];

        return [
            "title" => $structure["title"],
            "title_slug" => $structure["title_slug"],
            "uuid" => $structure["uuid"],
            "domain_dir" => $structure["domain_dir"],
            "assets_dir" => $structure["assets_dir"],
            "research_dir" => $structure["research_dir"],
            "research_text" => $track["research_text"] ?? "",
            "domain" => $track["domain"],
            "type" => $track["type"],
            "template" => $track["template"],
            "track" => $track["track"],
            "track_type" => $track["track_type"],
            "workflow" => $track["workflow"],
            "lang" => $track["lang"],
            "content_json" => $track["content_json"],
            "content_md" => $track["content_md"],
            "description_md" => $track["description_md"],
        ];
    }
}
