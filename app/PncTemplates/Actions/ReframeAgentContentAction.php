<?php

namespace App\PncTemplates\Actions;

use App\PncTemplates\Contracts\TemplateTransformerInterface;
use App\PncServices\Contracts\AgentDataInterface;
use InvalidArgumentException;

class ReframeAgentContentAction
{
    public function __construct(
        protected TemplateTransformerInterface $transformer,
    ) {}

    /**
     * Executes the AI content workflow generation loop, then strips/transforms
     * it using your external file layout templates.
     */
    public function execute(
        string $trackName,
        string $templateFilename,
        array $payload,
    ): array {
        // Break infinite recursion by lazily resolving AgentDataInterface on-demand
        /** @var AgentDataInterface $agentStore */
        $agentStore = app(AgentDataInterface::class);

        // Scaffold directory structures safely through your baseline store manager
        $trackPayload = $payload;
        $trackPayload["content_track"] = $trackName;
        $targetDirectory = $agentStore->createStructure($trackPayload);

        // TODO: Integrate your custom workflow engine here in the future.
        // For now, we mock the required placeholder variables to keep the template transformer operational.
        $markdownResultText =
            "# Placeholder Content\nGenerated track for: " . $trackName;
        $baseSkillKey = "sales_marketing.marketing.external_documentation.{$trackName}";

        // --- CASCADING TEMPLATE PATH RESOLUTION ---
        $filename = $templateFilename . ".json";
        $templatePath = null;

        // Check the Environment Overrides Directory first
        $overrideDir = rtrim(env("TEMPLATE_OVERRIDES", ""), "/");
        if (!empty($overrideDir) && file_exists("{$overrideDir}/{$filename}")) {
            $templatePath = "{$overrideDir}/{$filename}";
        }
        // Fall back to your core framework Defaults folder
        elseif (file_exists(app_path("PncTemplates/Templates/{$filename}"))) {
            $templatePath = app_path("PncTemplates/Templates/{$filename}");
        }
        // Ultimate Fallback: Default system schema structure rules
        elseif (file_exists(app_path("PncTemplates/Templates/default.json"))) {
            $templatePath = app_path("PncTemplates/Templates/default.json");
        }

        // Raise an error if absolutely no config blueprint can be resolved anywhere
        if (!$templatePath) {
            throw new InvalidArgumentException(
                "Critical Error: Could not resolve template mapping '{$filename}' " .
                    "in override paths, core defaults, or global fallback 'default.json'.",
            );
        }

        // --- TRANSFORM MARKDOWN INTO HEADLESS MATRIX CHUNKS ---
        $transformationMatrix = $this->transformer->transform(
            $templatePath,
            $markdownResultText,
        );

        // Persist structured artifacts to disk
        file_put_contents("{$targetDirectory}/content.md", $markdownResultText);
        file_put_contents(
            "{$targetDirectory}/content.json",
            json_encode(
                $transformationMatrix["raw_json_fields"],
                JSON_PRETTY_PRINT,
            ),
        );

        foreach (
            $transformationMatrix["html_elements"]
            as $elementKey => $htmlSnippet
        ) {
            @file_put_contents(
                "{$targetDirectory}/element_{$elementKey}.html",
                $htmlSnippet,
            );
        }

        return [
            "target_directory" => $targetDirectory,
            "base_skill_key" => $baseSkillKey,
            "version_count" => 0,
            "extracted_fields" => $transformationMatrix["raw_json_fields"],
        ];
    }
}
