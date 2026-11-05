<?php

namespace App\PncServices\Agent;

use App\PncServices\Contracts\AgentDataInterface;
use App\PncServices\Agent\Actions\CreateAgentStructureAction;
use App\PncServices\Agent\Actions\ResearchAgentContentAction;
use App\PncServices\Agent\Actions\WorkFlowAgentContentAction;
use App\PncServices\Agent\Actions\TemplateAgentContentAction;
use App\PncServices\Agent\Actions\SaveAgentContentAction;

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
     */
    public function createStructure(array $data): array
    {
        return $this->createStructureAction->execute($data);
    }

    /**
     * Runs research content generation for the given structure.
     */
    public function runResearch(array $structure): array
    {
        return $this->researchAction->execute($structure);
    }

    /**
     * Executes the full workflow pipeline for the given structure.
     */
    public function runWorkFlow(array $structure): array
    {
        return $this->workFlowAction->execute($structure);
    }

    /**
     * Applies template-based content generation to the given structure.
     */
    public function applyTemplate(array $structure): array
    {
        return $this->templateAction->execute($structure);
    }

    /**
     * Applies save content generation to the given structure.
     */
    public function save(array $structure): array
    {
        return $this->saveAction->execute($structure);
    }

    /**
     * Convenience: runs the full pipeline (structure → template → research → workflow) in sequence.
     */
    public function executeFullCreationPipeline(array $data): array
    {
        $structure = $this->createStructure($data);

        // $research = $this->runResearch($structure);
        //  $workFlow = $this->runWorkFlow($structure);
        //  $template = $this->applyTemplate($structure);
        //  $save = $this->applySave($template);

        return [
            "structure" => $structure,
            //    "research" => $research,
            //     "template" => $template,
            //    "work_flow" => $workFlow,
            // "save" => $save,
        ];
    }
}
