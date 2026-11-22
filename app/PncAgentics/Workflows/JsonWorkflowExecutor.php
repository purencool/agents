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
use App\PncServices\Contracts\ContentPersistenceInterface;

/**
 * Data-driven workflow executor.
 *
 * Orchestrates the execution of a JSON workflow definition by:
 *  - Loading the definition via {@see WorkflowDefinitionLoader}
 *    (3-tier resolution: overlay → config override → default dir, with
 *    fallback to "default.json").
 *  - Dispatching linear stages directly (skill call → transform → store in context).
 *  - Dispatching loop stages via {@see LoopStageExecutor}
 *    (iteration, per-iteration reset, sub-stage execution, accumulation).
 *  - Persisting the final output via {@see WorkflowPersister} when a
 *    "persist" block is defined in the workflow JSON.
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
 *     ],
 *     "persist": {
 *       "path": "{{domain_dir}}",
 *       "filename": "{{title_slug}}.md",
 *       "output_key": "body"
 *     }
 *   }
 *
 * @example
 *   $executor = app(JsonWorkflowExecutor::class);
 *   $output = $executor->execute("universal_documentation", [
 *       "title"         => "My Article",
 *       "research_text" => "Pre-gathered research...",
 *       "domain_dir"    => "/output/articles",
 *       "title_slug"    => "my-article",
 *   ]);
 */
class JsonWorkflowExecutor implements
    LoopStageExecutorContractInterface,
    LoopStageResolverContractInterface
{
    /**
     * Resolves and loads workflow definition JSON files from disk.
     * Handles the 3-tier resolution order and default.json fallback.
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
     * Persists the final workflow output to disk via ContentPersistenceInterface.
     */
    protected WorkflowPersister $persister;

    /**
     * @param SkillExecutorInterface      $executor       The skill executor for LLM calls.
     * @param ContentPersistenceInterface $persistence    Content persistence service.
     * @param string                      $definitionsDir Base directory for default workflow JSON files.
     */
    public function __construct(
        protected SkillExecutorInterface $executor,
        protected ContentPersistenceInterface $persistence,
        protected string $definitionsDir,
    ) {
        $this->loader = new WorkflowDefinitionLoader($definitionsDir);
        $this->resolver = new TemplateResolver();
        $this->transform = new StageTransform();
        $this->persister = new WorkflowPersister(
            $this->persistence,
            $this->resolver,
        );
        $this->loopExecutor = new LoopStageExecutor($this, $this);
    }

    /**
     * Execute a workflow by name.
     *
     * Execution flow:
     *  1. Load the workflow definition (delegated to WorkflowDefinitionLoader).
     *  2. Iterate through each stage in order:
     *     - Loop stages (type === "loop") → delegated to LoopStageExecutor.
     *     - Linear stages → executed via executeStage().
     *  3. If a "persist" block is defined, persist the output to disk.
     *  4. Return the context value corresponding to the last stage's
     *     "output_key" (or "accumulate.key" for loop stages).
     *
     * The return value is always a string. If the final context value is an
     * array (e.g. from a json_decode transform), it is JSON-encoded.
     *
     * @param string $workflowName The workflow identifier (filename without .json).
     * @param array  $context      Initial context variables available to all stages
     *                             (e.g. "title", "research_text", "domain_dir", "type").
     *
     * @return string The final output from the last stage's output_key, or "" if no output key is defined.
     *
     * @throws \RuntimeException When the workflow definition file is not found or contains invalid JSON.
     */
    public function execute(string $workflowName, array $context): string
    {
        $definition = $this->loader->load($workflowName);

        foreach ($definition["stages"] as $stage) {
            if (isset($stage["type"]) && $stage["type"] === "loop") {
                $this->loopExecutor->execute($stage, $context);
            } else {
                $this->executeStage($stage, $context);
            }
        }

        if (!empty($definition["persist"])) {
            $this->persister->persist($definition["persist"], $context);
        }

        $lastStage = $definition["stages"][count($definition["stages"]) - 1];
        $outputKey =
            $lastStage["output_key"] ??
            ($lastStage["accumulate"]["key"] ?? null);

        if ($outputKey === null) {
            return "";
        }

        $result = $context[$outputKey] ?? "";
        return is_array($result) ? json_encode($result) : (string) $result;
    }

    /**
     * Execute a single linear stage.
     *
     * Execution flow:
     *  1. Resolve {{key}} variables from context (via TemplateResolver).
     *  2. Resolve the "skill" field (supports {{key}} interpolation for
     *     dynamic skill selection from context).
     *  3. Call the skill executor with the resolved skill name, variables,
     *     and options.
     *  4. If a "transform" is defined, apply it to the result.
     *  5. Store the (possibly transformed) result in context under
     *     the stage's "output_key".
     *
     * Satisfies {@see LoopStageExecutorContractInterface::executeStage()}.
     *
     * @param array $stage   Stage definition from the workflow JSON.
     *                       Required keys: "skill", "output_key".
     *                       Optional keys: "variables" (array of {{key}} templates),
     *                       "options" (LLM config overrides), "transform" (string).
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
     * If the entire string is a single {{key}}, the raw context value is
     * returned (preserving type — arrays, numbers, etc.). Otherwise, the
     * string is treated as a template and all placeholders are replaced.
     *
     * Satisfies {@see LoopStageResolverContractInterface::resolveVariable()}.
     *
     * @param string $key     Template string with {{key}} placeholders.
     * @param array  $context Current context.
     *
     * @return mixed The resolved value (raw value if single key reference, string if template).
     */
    public function resolveVariable(string $key, array $context): mixed
    {
        return $this->resolver->resolveVariable($key, $context);
    }

    /**
     * Replace all {{key}} placeholders in a template string with context values.
     *
     * Placeholders that don't match a context key are replaced with an
     * empty string. Array and object values are JSON-encoded to prevent
     * "Array to string conversion" errors.
     *
     * Satisfies {@see LoopStageResolverContractInterface::resolveTemplate()}.
     *
     * @param string $template Template string.
     * @param array  $context  Current context.
     *
     * @return string The template with all resolvable placeholders replaced.
     */
    public function resolveTemplate(string $template, array $context): string
    {
        return $this->resolver->resolveTemplate($template, $context);
    }
}
