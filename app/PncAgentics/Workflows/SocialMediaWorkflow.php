<?php

namespace App\PncAgentics\Workflows;

use App\PncAgentics\Contracts\WorkflowInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Contracts\FileSaverInterface;

class SocialMediaWorkflow implements WorkflowInterface
{
    public function __construct(
        protected SkillExecutorInterface $executor,
        protected McpToolServiceInterface $mcp,
        protected FileSaverInterface $saver,
    ) {}

    public function execute(
        string $baseSkillKey,
        array $payload,
        string $targetDirectory,
        ?string $existingContent = null,
    ): string {
        $refinementContext = $existingContent ?? "";
        $platform = $payload["platform"] ?? "generic";
        $tone = $payload["tone"] ?? "casual";
        $cta = $payload["cta"] ?? "";

        // ─── Research ───
        $gatherer = new ResearchGatherer($this->mcp, $this->saver);
        $researchContext = $gatherer->gather($payload, $targetDirectory);
        $refinementContext .= "\n\n## Research Findings\n" . $researchContext;

        // ─── Hook ───
        $hook = $this->executor->execute(
            "{$baseSkillKey}.social_media.hook_writer.skills",
            [
                "title" => $payload["title"],
                "description" => $payload["description"] ?? "",
                "platform" => $platform,
                "tone" => $tone,
                "refinement_context" => $refinementContext,
            ],
            ["temperature" => 0.6],
        );

        // ─── Body ───
        $body = $this->executor->execute(
            "{$baseSkillKey}.social_media.body_writer.skills",
            [
                "hook" => $hook,
                "title" => $payload["title"],
                "description" => $payload["description"] ?? "",
                "platform" => $platform,
                "tone" => $tone,
                "refinement_context" => $refinementContext,
            ],
            ["temperature" => 0.5],
        );

        // ─── CTA ───
        $ctaSection = $this->executor->execute(
            "{$baseSkillKey}.social_media.cta_writer.skills",
            [
                "hook" => $hook,
                "body" => $body,
                "cta" => $cta,
                "platform" => $platform,
                "refinement_context" => $refinementContext,
            ],
            ["temperature" => 0.4],
        );

        // ─── Hashtags ───
        $hashtags = $this->executor->execute(
            "{$baseSkillKey}.social_media.hashtag_generator.skills",
            [
                "title" => $payload["title"],
                "body" => $body,
                "platform" => $platform,
                "refinement_context" => $refinementContext,
            ],
            ["temperature" => 0.3],
        );

        // ─── Assemble ───
        $output = "{$hook}\n\n{$body}\n\n{$ctaSection}\n\n{$hashtags}";

        // ─── Platform-specific trim ───
        $output = $this->applyPlatformLimits($output, $platform);

        // ─── Save ───
        $this->saver->setPath($targetDirectory);
        $this->saver->saveMarkdown($output, "social_{$platform}");
        $this->saver->saveJson([
            'platform' => $platform,
            'tone' => $tone,
            'hook' => $hook,
            'body' => $body,
            'cta' => $ctaSection,
            'hashtags' => $hashtags,
            'char_count' => strlen($output),
        ], "social_{$platform}_parts");

        return $output;
    }

    /**
     * Trim output to platform character limits.
     */
    protected function applyPlatformLimits(string $output, string $platform): string
    {
        $limits = [
            'twitter'    => 280,
            'x'          => 280,
            'linkedin'   => 3000,
            'instagram'  => 2200,
            'facebook'   => 63206,
            'tiktok'     => 4000,
            'generic'    => 500,
        ];

        $limit = $limits[$platform] ?? 500;

        if (strlen($output) > $limit) {
            $output = substr($output, 0, $limit - 1) . "…";
        }

        return $output;
    }
}
