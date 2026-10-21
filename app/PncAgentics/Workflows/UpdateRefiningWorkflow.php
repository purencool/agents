<?php

namespace App\PncAgentics\Workflows;

use App\PncAgentics\Contracts\WorkflowInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;

class UpdateRefiningWorkflow implements WorkflowInterface
{
    public function __construct(protected SkillExecutorInterface $executor) {}

    /**
     * Executes the text refinement and update pipeline using the dynamic enterprise base key.
     */
    public function execute(
        string $baseSkillKey,
        array $payload,
        string $targetDirectory,
        ?string $existingContent = null,
    ): string {
        // Leverage the dynamic review agents matching your active content track (e.g. developer_docs, product_guides)
        // to incrementally rewrite text structures based on new instructions.
        $reframed = $this->executor->execute(
            "{$baseSkillKey}.review_refinement.transition_flow_smoothness_agent.skills",
            [
                "original" => $existingContent ?? "",
                "reframing_instructions" => $payload["description"],
            ],
        );

        // Pass the updated draft through the structural markdown formatting agent for final polish.
        return $this->executor->execute(
            "{$baseSkillKey}.review_refinement.formatting_markdown_expert.skills",
            ["content" => $reframed],
        );
    }
}
