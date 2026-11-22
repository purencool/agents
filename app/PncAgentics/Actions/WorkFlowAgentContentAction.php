<?php

declare(strict_types=1);

namespace App\PncAgentics\Actions;

use App\PncAgentics\Workflows\JsonWorkflowExecutor;

/**
 * Resolves the correct workflow for a content structure and executes it.
 *
 * Reads the "workflow" field from the first track in the structure, maps it
 * to a workflow definition filename, and delegates execution to
 * {@see JsonWorkflowExecutor}. The resulting content string is stored in the
 * structure's "content" key for downstream templating.
 *
 * Workflow resolution:
 *  - "social_media"  → "social_media.json"
 *  - anything else   → "universal_documentation.json" (default)
 *
 * The context passed to the executor is built from the structure's top-level
 * fields and the first track's track-specific fields (research_text, type, lang).
 *
 * @example
 *   $action = app(WorkFlowAgentContentAction::class);
 *   $structure = $action->execute($structure);
 *   // $structure["content"] now contains the generated markdown.
 */
class WorkFlowAgentContentAction
{
    public function __construct(private JsonWorkflowExecutor $executor) {}

    /**
     * Execute the resolved workflow and attach the output to the structure.
     *
     * The structure is returned with a new "content" key containing the
     * workflow's final output string. All other keys are passed through
     * unmodified.
     *
     * @param array<string, mixed> $structure The full content structure
     *                                         (uuid, title, tracks, etc.).
     *
     * @return array<string, mixed> The same structure with "content" added.
     */
    public function execute(array $structure): array
    {
        $workflowKey = $this->resolveWorkflowKey($structure);

        $structure["content"] = $this->executor->execute(
            $workflowKey,
            $this->buildContext($structure),
        );

        return $structure;
    }

    /**
     * Map the first track's "workflow" field to a workflow definition name.
     *
     * @param array<string, mixed> $structure
     *
     * @return string The workflow filename (without .json) to load.
     */
    private function resolveWorkflowKey(array $structure): string
    {
        $firstTrack = reset($structure["tracks"]);
        $workflow = $firstTrack["workflow"] ?? "default";

        return match ($workflow) {
            "social_media" => "social_media",
            default => "universal_documentation",
        };
    }

    /**
     * Build the context array passed to the workflow executor.
     *
     * Combines top-level structure fields (title, slugs, directories) with
     * first-track fields (research_text, type, lang) into a flat key-value
     * map that workflow stage templates can reference via {{key}}.
     *
     * @param array<string, mixed> $structure
     *
     * @return array<string, mixed> Context variables for template resolution.
     */
    private function buildContext(array $structure): array
    {
        $firstTrack = reset($structure["tracks"]);

        return [
            "title" => $structure["title"],
            "title_slug" => $structure["title_slug"],
            "domain_dir" => $structure["domain_dir"],
            "assets_dir" => $structure["assets_dir"],
            "research_dir" => $structure["research_dir"],
            "research_text" => $firstTrack["research_text"] ?? "",
            "type" => $firstTrack["type"] ?? "",
            "lang" => $firstTrack["lang"] ?? "en",
        ];
    }
}
