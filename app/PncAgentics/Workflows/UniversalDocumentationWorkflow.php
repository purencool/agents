<?php

namespace App\PncAgentics\Workflows;

use App\PncAgentics\Contracts\WorkflowInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;

class UniversalDocumentationWorkflow implements WorkflowInterface
{
    public function __construct(protected SkillExecutorInterface $executor) {}

    public function execute(
        string $baseSkillKey,
        array $payload,
        string $targetDirectory,
        ?string $existingContent = null,
    ): string {
        $refinementContext = $existingContent ?? "";
        $rawOutline = $this->executor->execute(
            "{$baseSkillKey}.research_preparation.outline_generator.skills",
            array_merge($payload, ["refinement_context" => $refinementContext]),
            ["temperature" => 0.2],
        );

        $sectionsJson = $this->executor->execute(
            "{$baseSkillKey}.orchestration.document_orchestrator.skills",
            [
                "outline" => $rawOutline,
                "format_instruction" =>
                    "Convert the outline sections into a plain JSON array of strings.",
                "refinement_context" => $refinementContext,
            ],
            ["temperature" => 0.1],
        );

        $cleanJson = preg_replace("/```json\s*|```/", "", $sectionsJson);
        $cleanJson = trim($cleanJson);
        $sections = json_decode($cleanJson, true) ?: [$payload["title"]];

        $bodyCanvas = "";

        foreach ($sections as $sectionTitle) {
            $coreDraft = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.batch_draft_engine.skills",
                [
                    "section" => $sectionTitle,
                    "outline" => $rawOutline,
                    "refinement_context" => $refinementContext,
                ],
                ["temperature" => 0.2],
            );

            $insightContent = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.analogy_example_creator.skills",
                [
                    "section" => $sectionTitle,
                    "content" => $coreDraft,
                    "refinement_context" => $refinementContext,
                ],
                ["temperature" => 0.3],
            );

            $critiqueContent = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.counter_argument_builder.skills",
                [
                    "section" => $sectionTitle,
                    "content" => $insightContent,
                    "refinement_context" => $refinementContext,
                ],
                ["temperature" => 0.2],
            );

            $valueContent = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.batch_expand_engine.skills",
                [
                    "section" => $sectionTitle,
                    "content" => $critiqueContent,
                    "refinement_context" => $refinementContext,
                ],
                ["temperature" => 0.2],
            );

            $rawSectionBlock = $critiqueContent . "\n\n" . $valueContent;

            $polishedSection = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.internal_transition_smoother.skills",
                [
                    "content" => $rawSectionBlock,
                    "refinement_context" => $refinementContext,
                ],
                ["temperature" => 0.2],
            );

            $bodyCanvas .= "\n\n## {$sectionTitle}\n\n" . $polishedSection;
        }

        $introduction = $this->executor->execute(
            "{$baseSkillKey}.core_writing.hook_introduction_writer.skills",
            [
                "title" => $payload["title"],
                "description" => $payload["description"],
                "body_context" => $bodyCanvas,
                "refinement_context" => $refinementContext,
            ],
            ["temperature" => 0.4],
        );

        $conclusion = $this->executor->execute(
            "{$baseSkillKey}.core_writing.conclusion_writer.skills",
            [
                "introduction" => $introduction,
                "body" => $bodyCanvas,
                "tone" => $payload["type"] ?? "professional",
                "refinement_context" => $refinementContext,
            ],
            ["temperature" => 0.3],
        );

        $masterDraft = "# {$payload["title"]}\n\n{$introduction}\n\n{$bodyCanvas}\n\n## Conclusion\n\n{$conclusion}";

        $returnOutput = $this->executor->execute(
            "{$baseSkillKey}.review_refinement.formatting_markdown_expert.skills",
            [
                "content" => $masterDraft,
                "refinement_context" => $refinementContext,
            ],
            ["temperature" => 0.1],
        );

        return $returnOutput;
    }
}
