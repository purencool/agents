<?php

namespace App\PncAgentics\Workflows;

use App\PncAgentics\Contracts\WorkflowInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;

class ProductGuidesCreationWorkflow implements WorkflowInterface
{
    public function __construct(protected SkillExecutorInterface $executor) {}

    public function execute(
        array $payload,
        string $targetDirectory,
        ?string $existingContent = null,
    ): string {
        $styleGuide = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.research_preparation.tone_style_definer.skills",
            $payload,
        );

        $outline = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.research_preparation.outline_generator.skills",
            array_merge($payload, ["context" => "product_user_guide"]),
        );

        $introduction = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.core_writing.hook_introduction_writer.skills",
            [
                "title" => $payload["title"],
                "outline" => $outline,
                "style" => $styleGuide,
            ],
        );

        $guideBody = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.core_writing.body_content.list_bullet_generator.skills",
            [
                "outline" => $outline,
                "style" => $styleGuide,
                "description" => $payload["description"],
            ],
        );

        $conclusion = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.core_writing.conclusion_writer.skills",
            ["introduction" => $introduction, "body" => $guideBody],
        );

        $rawGuideDraft = "# {$payload["title"]}\n\n{$introduction}\n\n## Core Guide Features\n{$guideBody}\n\n{$conclusion}";

        $polishedDraft = $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.review_refinement.copyeditor_grammarian.skills",
            ["content" => $rawGuideDraft],
        );

        return $this->executor->execute(
            "sales_marketing.marketing.external_documentation.ai_agents.review_refinement.formatting_markdown_expert.skills",
            ["content" => $polishedDraft],
        );
    }
}
