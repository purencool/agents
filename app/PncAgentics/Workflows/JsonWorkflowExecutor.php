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
use RuntimeException;

/**
 * Data-driven workflow executor for multi-track content pipelines.
 *
 * Orchestrates execution of a JSON workflow definition across ALL tracks
 * in a content structure. For each track (domain → type-lang), it:
 *
 *  1. Builds a flat context from the shared structure + per-track fields.
 *  2. Loads the workflow definition via {@see WorkflowDefinitionLoader}.
 *  3. Iterates stages in order:
 *     - Loop stages (`type === "loop"`) → delegated to {@see LoopStageExecutor}.
 *     - Linear stages → executed via {@see self::executeStage()}.
 *  4. Extracts the final output and persists it to the track's content files.
 *
 * The original `$structure` array is returned with a `workflow_results`
 * key added — individual track entries are not mutated in-place.
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
 *   $result   = $executor->execute($structure);
 *   $output   = $result["workflow_results"]["example.com"]["typeone-en"];
 *
 * @see \App\PncAgentics\Actions\CreateAgentStructureAction
 */
class JsonWorkflowExecutor implements
    LoopStageExecutorContractInterface,
    LoopStageResolverContractInterface
{
    /**
     * Resolves and loads workflow definition JSON files from disk.
     *
     * Searches override dir first, then default dir, with fallback to
     * "default.json" when no named file exists.
     */
    protected WorkflowDefinitionLoader $loader;

    /**
     * Executes loop stages (iteration, per-iteration reset, sub-stage
     * dispatch, accumulation). Receives $this as both the stage executor
     * and the template resolver.
     */
    protected LoopStageExecutor $loopExecutor;

    /**
     * Resolves {{key}} placeholders from flat context arrays.
     */
    protected TemplateResolver $resolver;

    /**
     * Applies named output transforms (strip_code_fence, json_decode, etc.).
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
     * Execute workflows for ALL tracks in the structure.
     *
     * Iterates the two-level tracks array (domain → type-lang), runs the
     * workflow definition per track, persists output to disk, and attaches
     * a `workflow_results` key to the returned structure.
     *
     * Execution flow per track:
     *  1. Build a flat context via {@see self::buildContext()}.
     *  2. Load the workflow definition (delegated to WorkflowDefinitionLoader).
     *  3. Iterate stages in order:
     *     - Loop stages → {@see LoopStageExecutor::execute()}.
     *     - Linear stages → {@see self::executeStage()}.
     *  4. Extract the final output from the last stage's output_key.
     *  5. Persist via {@see self::persistTrackOutput()}.
     *
     * @param array{
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
     * } $structure The full content structure from CreateAgentStructureAction.
     *
     * @return array The same structure with "workflow_results" added:
     *               array<string, array<string, mixed>> keyed by domain → trackKey.
     *
     * @throws RuntimeException When a workflow definition file is not found
     *                          or contains invalid JSON.
     */
    public function execute(array $structure): array
    {
        $results = [];

        foreach ($structure["tracks"] as $domain => $domainTracks) {
            foreach ($domainTracks as $trackKey => $track) {
                $context = $this->buildContext($structure, $track);

                $definition = $this->loader->load($context["workflow"]);
                foreach ($definition["stages"] as $stage) {
                    if (isset($stage["type"]) && $stage["type"] === "loop") {
                        $this->loopExecutor->execute($stage, $context);
                    } else {
                        $this->executeStage($stage, $context);
                    }
                }

                $lastStage =
                    $definition["stages"][count($definition["stages"]) - 1];
                $outputKey =
                    $lastStage["output_key"] ??
                    ($lastStage["accumulate"]["key"] ?? null);

                $raw = $outputKey !== null ? $context[$outputKey] ?? "" : "";

                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    $raw = is_array($decoded) ? $decoded : ["text" => $raw];
                }

                $results[$domain][$trackKey] = $raw;

                $this->persistTrackOutput($track, $raw, $context);
            }
        }

        $structure["workflow_results"] = $results;

        return $structure;
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
     * @param array{
     *     skill: string,
     *     variables?: array<string, string>,
     *     options?: array<string, mixed>,
     *     output_key: string,
     *     transform?: string
     * } $stage   Stage definition from the workflow JSON.
     * @param array<string, mixed> $context Mutable context (modified in-place).
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
     * @param array  $context Current flat context.
     *
     * @return mixed The resolved value (string, int, array, etc.).
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
     * @param array  $context  Current flat context.
     *
     * @return string The resolved template with all placeholders replaced.
     */
    public function resolveTemplate(string $template, array $context): string
    {
        return $this->resolver->resolveTemplate($template, $context);
    }

    /**
     * Build a flat context array for ONE track from the shared structure.
     *
     * Merges top-level structure fields (title, uuid, dirs) with per-track
     * fields (domain, context, type, template, workflow, lang, paths) into
     * a single flat array suitable for {{key}} template resolution.
     *
     * The original $structure is never mutated — this returns a new array.
     *
     * @param array{
     *     uuid: string,
     *     title: string,
     *     title_slug: string,
     *     description: string,
     *     categories: string[],
     *     domain_dir: string,
     *     assets_dir: string,
     *     research_dir: string
     * } $structure The top-level structure.
     * @param array{
     *     domain: string,
     *     context: string,
     *     type: string,
     *     template: string,
     *     track: string,
     *     track_type: string,
     *     workflow: string,
     *     lang: string,
     *     content_path_json: string,
     *     content_path_markdown: string,
     *     content_description: string
     * } $track A single track entry from tracks[domain][type-lang].
     *
     * @return array<string, mixed> Flat context for stage execution.
     */
    protected function buildContext(array $structure, array $track): array
    {
        return [
            "uuid" => $structure["uuid"],
            "title" => $structure["title"],
            "title_slug" => $structure["title_slug"],
            "description" => $structure["description"],
            "categories" => $structure["categories"],
            "domain_dir" => $structure["domain_dir"],
            "assets_dir" => $structure["assets_dir"],
            "research_dir" => $structure["research_dir"],
            "domain" => $track["domain"],
            "context" => $track["context"],
            "type" => $track["type"],
            "template" => $track["template"],
            "track" => $track["track"],
            "track_type" => $track["track_type"],
            "workflow" => $track["workflow"],
            "lang" => $track["lang"],
            "content_path_json" => $track["content_path_json"],
            "content_path_markdown" => $track["content_path_markdown"],
            "content_description" => $track["content_description"],
        ];
    }

    /**
     * Write the workflow output back to the track's content files on disk.
     *
     * Updates two files (when their paths are non-empty):
     *  - content_path_json     — merges the result into the existing JSON under "content".
     *  - content_path_markdown — overwrites with the text representation of the result.
     *
     * If $raw is an array, the markdown file receives the "text" key if present,
     * otherwise a JSON-encoded fallback.
     *
     * @param array{
     *     content_path_json: string,
     *     content_path_markdown: string
     * } $track The track entry (only path fields are used).
     * @param mixed $raw   The final workflow output (string or array).
     * @param array $context The flat context (available for future use).
     */
    protected function persistTrackOutput(
        array $track,
        mixed $raw,
        array $context,
    ): void {
        if (
            !empty($track["content_path_json"]) &&
            file_exists($track["content_path_json"])
        ) {
            $data =
                json_decode(
                    (string) file_get_contents($track["content_path_json"]),
                    true,
                ) ?? [];
            $data["content"] = $raw;
            file_put_contents(
                $track["content_path_json"],
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
        }

        if (!empty($track["content_path_markdown"])) {
            $text = is_array($raw)
                ? $raw["text"] ?? json_encode($raw)
                : (string) $raw;
            file_put_contents($track["content_path_markdown"], $text);
        }
    }
}
