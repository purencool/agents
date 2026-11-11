<?php

namespace App\PncServices\Agent\Actions;

use App\PncAgentics\Workflows\Types\SocialMediaWorkflow;
use App\PncAgentics\Workflows\Types\UniversalDocumentationWorkflow;

/**
 * Resolves the appropriate workflow and delegates the full structure to it.
 *
 * This action performs no data transformation. It inspects the first track's
 * "workflow" field to select the correct workflow instance, then passes
 * the entire structure array directly to the workflow.
 *
 * Workflow selection:
 *  - "default" or empty string → {@see UniversalDocumentationWorkflow}
 *  - "social_media"            → {@see SocialMediaWorkflow}
 *  - anything else             → {@see UniversalDocumentationWorkflow} (fallback)
 */
class WorkFlowAgentContentAction
{
    public function __construct(
        private readonly UniversalDocumentationWorkflow $universalWorkflow,
        private readonly SocialMediaWorkflow $socialMediaWorkflow,
    ) {}

    /**
     * Resolves the workflow and executes it with the full structure.
     *
     * @param array{
     *     uuid: string,
     *     domain_dir: string,
     *     assets_dir: string,
     *     research_dir: string,
     *     title: string,
     *     title_slug: string,
     *     tracks: array<string, array{
     *         domain: string,
     *         type: string,
     *         template: string,
     *         track: string,
     *         track_type: string,
     *         workflow: string,
     *         lang: string,
     *         content_json: string,
     *         content_md: string,
     *         description_md: string,
     *         research_text: string
     *     }>
     * } $structure
     *
     * @return array<string, mixed> The structure unmodified (the workflow
     *                              saves output to disk internally).
     */
    public function execute(array $structure): array
    {
        $firstTrackKey = array_key_first($structure["tracks"]);
        $workflow = $this->resolveWorkflow(
            $structure["tracks"][$firstTrackKey]["workflow"],
        );

        $workflow->execute($structure);

        return $structure;
    }

    /**
     * Maps the workflow identifier to the concrete workflow instance.
     *
     * @param string $workflowIdentifier Value of the track's "workflow" field.
     */
    private function resolveWorkflow(string $workflowIdentifier): object
    {
        return match (trim($workflowIdentifier)) {
            "", "default" => $this->universalWorkflow,
            "social_media" => $this->socialMediaWorkflow,
            default => $this->universalWorkflow,
        };
    }
}
