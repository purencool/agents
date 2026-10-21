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
        $tone = $this->executor->execute(
            "{$baseSkillKey}.research_preparation.tone_style_definer.skills",
            $payload,
        );
        $rawOutline = $this->executor->execute(
            "{$baseSkillKey}.research_preparation.outline_generator.skills",
            $payload,
        );
        $factCheckMatrix = $this->executor->execute(
            "{$baseSkillKey}.research_preparation.fact_checker.skills",
            [
                "title" => $payload["title"],
                "description" => $payload["description"],
                "outline" => $rawOutline,
            ],
        );

        // Resolve outline headings into structural loops cleanly
        $sectionsJson = $this->executor->execute(
            "{$baseSkillKey}.orchestration.document_orchestrator.skills",
            [
                "outline" => $rawOutline,
                "format_instruction" =>
                    "Convert the outline sections into a plain JSON array of strings.",
            ],
        );

        $cleanJson = preg_replace("/```json\s*|```/", "", $sectionsJson);
        $cleanJson = trim($cleanJson);

        $sections = json_decode($cleanJson, true) ?: [$payload["title"]];
        $bodyCanvas = "";

        foreach ($sections as $sectionTitle) {
            $topicOpener = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.topic_sentence_opener.skills",
                ["section" => $sectionTitle, "tone" => $tone],
            );
            $bullets = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.list_bullet_generator.skills",
                ["section" => $sectionTitle, "context" => $topicOpener],
            );
            $technicalSteps = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.code_technical_step_writer.skills",
                ["section" => $sectionTitle, "context" => $bullets],
                ["temperature" => 0.1],
            );
            $analogy = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.analogy_example_creator.skills",
                ["content" => $technicalSteps],
            );
            $evidence = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.evidence_data_inserter.skills",
                ["content" => $analogy, "facts" => $factCheckMatrix],
            );
            $analysis = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.analysis_commentary_writer.skills",
                ["content" => $evidence],
            );

            // Context Isolation Protection: Send only the target core slice
            $counterArgs = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.counter_argument_builder.skills",
                ["content" => $analysis],
            );
            $rebuttal = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.rebuttal_resolution_writer.skills",
                ["content" => $counterArgs],
            );
            $callouts = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.callout_key_takeaway_writer.skills",
                ["content" => $analysis],
            );
            $glossary = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.glossary_definition_provider.skills",
                ["content" => $analysis],
            );

            // Stitched Assembly Frame
            $rawSectionBlock =
                $analysis .
                "\n\n" .
                "### Considerations\n" .
                $rebuttal .
                "\n\n" .
                "💡 **Key Takeaways:**\n" .
                $callouts .
                "\n\n" .
                "📖 **Terminology:**\n" .
                $glossary;

            // Transition Smoothing pass on the complete node segment
            $polishedSection = $this->executor->execute(
                "{$baseSkillKey}.core_writing.body_content.internal_transition_smoother.skills",
                ["content" => $rawSectionBlock],
            );

            $bodyCanvas .= "\n\n## {$sectionTitle}\n\n" . $polishedSection;
        }

        // The introduction agent now analyzes the finished body to write a perfect hook
        $introduction = $this->executor->execute(
            "{$baseSkillKey}.core_writing.hook_introduction_writer.skills",
            [
                "title" => $payload["title"],
                "description" => $payload["description"],
                "tone" => $tone,
                "body_context" => $bodyCanvas, // Injected with rich body structural data live!
            ],
        );

        // The conclusion agent compiles the exit strategy wrap smoothly
        $conclusion = $this->executor->execute(
            "{$baseSkillKey}.core_writing.conclusion_writer.skills",
            [
                "introduction" => $introduction,
                "body" => $bodyCanvas,
                "tone" => $tone,
            ],
        );

        // Assemble the Master Blueprint Layout
        $masterDraft = "# {$payload["title"]}\n\n{$introduction}\n\n{$bodyCanvas}\n\n## Conclusion\n\n{$conclusion}";

        $editedText = $this->executor->execute(
            "{$baseSkillKey}.review_refinement.copyeditor_grammarian.skills",
            ["content" => $masterDraft],
        );
        $fluidText = $this->executor->execute(
            "{$baseSkillKey}.review_refinement.transition_flow_smoothness_agent.skills",
            ["content" => $editedText],
        );

        return $this->executor->execute(
            "{$baseSkillKey}.review_refinement.formatting_markdown_expert.skills",
            ["content" => $fluidText],
            ["temperature" => 0.1],
        );
    }
}
