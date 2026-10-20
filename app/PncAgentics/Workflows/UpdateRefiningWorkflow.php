<?php

namespace App\PncAgentics\Workflows;

use App\PncAgentics\Contracts\WorkflowInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;

class UpdateRefiningWorkflow implements WorkflowInterface
{
    public function __construct(protected SkillExecutorInterface $executor) {}

    public function execute(
        array $payload,
        string $targetDirectory,
        ?string $existingContent = null,
    ): string {
        // Uses review agents to incrementally change text structures using history mappings
        $reframed = $this->executor->execute(
            "ai_agents.review_refinement.transition_flow_smoothness_agent",
            [
                "original" => $existingContent ?? "",
                "reframing_instructions" => $payload["description"],
            ],
        );

        return $this->executor->execute(
            "ai_agents.review_refinement.formatting_markdown_expert",
            ["content" => $reframed],
        );
    }
}
