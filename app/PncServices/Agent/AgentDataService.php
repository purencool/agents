<?php

namespace App\PncServices\Agent;

use App\PncServices\Contracts\AgentDataInterface;
use App\PncServices\Agent\Actions\CreateAgentStructureAction;
use App\PncServices\Agent\Actions\ResearchAgentContentAction;
use App\PncServices\Agent\Actions\WorkFlowAgentContentAction;
use App\PncServices\Agent\Actions\TemplateAgentContentAction;
use App\PncServices\Agent\Actions\SaveAgentContentAction;

/**
 * Orchestrates the full agent content creation pipeline.
 *
 * Delegates each pipeline stage to a dedicated Action class:
 * structure → template → research → workflow → save.
 */
class AgentDataService implements AgentDataInterface
{
    public function __construct(
        protected CreateAgentStructureAction $createStructureAction,
        protected ResearchAgentContentAction $researchAction,
        protected WorkFlowAgentContentAction $workFlowAction,
        protected TemplateAgentContentAction $templateAction,
        protected SaveAgentContentAction $saveAction,
    ) {}

    /**
     * Verifies and generates the agent filesystem hierarchy.
     *
     * @param array{
     *     title: string,
     *     description: string,
     *     domains: array<string, array<string, array{content_tracks: array{config: array}}>>
     * } $data Validated payload containing title, description, and domain/track config.
     *
     * @return array{
     *     uuid: string,
     *     paths: array<string, string>,
     *     tracks: array<string, array>
     * } Generated structure with UUID, filesystem paths, and track file mappings.
     */
    public function createStructure(array $data): array
    {
        return $this->createStructureAction->execute($data);
    }

    /**
     * Runs research content generation for the given structure.
     *
     * @param array $structure Return value from {@see self::createStructure()}.
     *
     * @return array{
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
     */
    public function runResearch(array $structure): array
    {
        return $this->researchAction->execute($structure);
    }

    /**
     * Executes the full workflow pipeline for the given structure.
     *
     * @param array $structure Return value from {@see self::createStructure()}.
     *
     * @return array<string, array> Results keyed by track identifier.
     */
    public function runWorkFlow(array $structure): array
    {
        return $this->workFlowAction->execute($structure);
    }

    /**
     * Applies template-based content generation to the given structure.
     *
     * @param array $structure Return value from {@see self::createStructure()}.
     *
     * @return array<string, array> Results keyed by track identifier.
     */
    public function applyTemplate(array $structure): array
    {
        return $this->templateAction->execute($structure);
    }

    /**
     * Persists generated content to the filesystem.
     *
     * @param array $structure Return value from {@see self::createStructure()}.
     *
     * @return array<string, array> Results keyed by track identifier.
     */
    public function save(array $structure): array
    {
        return $this->saveAction->execute($structure);
    }

    /**
     * Runs the full pipeline (structure → template → research → workflow → save) in sequence.
     *
     * @param array{
     *     title: string,
     *     description: string,
     *     domains: array<string, array<string, array{content_tracks: array{config: array}}>>
     * } $data Validated payload.
     *
     * @return array{
     *     structure: array{
     *         uuid: string,
     *         paths: array<string, string>,
     *         tracks: array<string, array>
     *     }
     * } Combined results from all pipeline stages.
     *
     * @todo Uncomment and wire up remaining stages (workflow, template, save).
     */
    public function executeFullCreationPipeline(array $data): array
    {
        $structure = $this->createStructure($data);
        $research = $this->runResearch($structure);
        $workFlow = $this->runWorkFlow($research);
        // $template = $this->applyTemplate($structure);
        // $save     = $this->save($template);

        return [
            "structure" => $structure,
            "research" => $research,
            // "template"  => $template,
            // "work_flow" => $workFlow,
            // "save"      => $save,
        ];
    }
}
