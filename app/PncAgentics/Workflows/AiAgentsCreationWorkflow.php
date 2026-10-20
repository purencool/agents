<?php

namespace App\PncAgentics\Workflows;

use App\PncAgentics\Contracts\WorkflowInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;

class AiAgentsCreationWorkflow implements WorkflowInterface
{
    public function __construct(protected SkillExecutorInterface $executor) {}

    public function execute(
        array $payload,
        string $targetDirectory,
        ?string $existingContent = null,
    ): string {
        // Explicitly target your enterprise namespaces matching the exact file structure paths
        $outline = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.research_preparation.outline_generator.skills",
            $payload,
        );
        $tone = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.research_preparation.tone_style_definer.skills",
            $payload,
        );

        $intro = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.core_writing.hook_introduction_writer.skills",
            ["outline" => $outline, "tone" => $tone],
        );
        $body = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.core_writing.body_content.code_technical_step_writer.skills",
            ["outline" => $outline, "tone" => $tone],
        );
        $conclusion = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.core_writing.conclusion_writer.skills",
            ["intro" => $intro, "body" => $body],
        );

        $rawText = "# {$payload["title"]}\n\n{$intro}\n\n{$body}\n\n{$conclusion}";

        $edited = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.review_refinement.copyeditor_grammarian.skills",
            ["content" => $rawText],
        );
        return $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.review_refinement.formatting_markdown_expert.skills",
            ["content" => $edited],
        );
    }
}
