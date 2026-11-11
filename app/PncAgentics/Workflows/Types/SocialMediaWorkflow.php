<?php

namespace App\PncAgentics\Workflows\Types;

use App\PncAgentics\Contracts\WorkflowInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Contracts\FileSaverInterface;

/**
 * Generates a full documentation-style article for every track in the
 * structure using a multi-stage skill pipeline
 * (outline → sections → introduction → conclusion → polish).
 *
 * Research was already gathered in an earlier pipeline stage; the track's
 * "research_text" field provides that context. Generated content is saved
 * to the file path specified in each track's "content_md" field.
 */
class SocialMediaWorkflow implements WorkflowInterface
{
    public function __construct(
        protected SkillExecutorInterface $executor,
        protected FileSaverInterface $saver,
    ) {}

    /**
     * Executes the documentation generation pipeline for all tracks.
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
     * @return string The final polished markdown from the last processed track.
     */
    public function execute(array $structure): string
    {
        $targetDirectory = $structure["domain_dir"];
        $title = $structure["title"];

        $lastOutput = "";

        foreach ($structure["tracks"] as $trackKey => $track) {
            $baseSkillKey = $trackKey;
            $description = $track["research_text"];
            $type = $track["type"];

            $refinementContext =
                "\n\n## Research Findings\n" . $track["research_text"];

            $rawOutline = $this->executor->execute(
                "{$baseSkillKey}.research_preparation.outline_generator.skills",
                [
                    "title" => $title,
                    "description" => $description,
                    "refinement_context" => $refinementContext,
                ],
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
            $sections = json_decode($cleanJson, true) ?: [$title];
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
                    "title" => $title,
                    "description" => $description,
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
                    "tone" => $type,
                    "refinement_context" => $refinementContext,
                ],
                ["temperature" => 0.3],
            );

            $masterDraft = "# {$title}\n\n{$introduction}\n\n{$bodyCanvas}\n\n## Conclusion\n\n{$conclusion}";

            $output = $this->executor->execute(
                "{$baseSkillKey}.review_refinement.formatting_markdown_expert.skills",
                [
                    "content" => $masterDraft,
                    "refinement_context" => $refinementContext,
                ],
                ["temperature" => 0.1],
            );

            $this->saver->setPath($targetDirectory);
            $this->saver->saveMarkdown(
                $output,
                basename($track["content_md"], ".md"),
            );

            $lastOutput = $output;
        }

        return $lastOutput;
    }
}
