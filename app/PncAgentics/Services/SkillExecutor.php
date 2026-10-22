<?php

namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Data\SkillOptions;
use App\PncServices\Contracts\AIServiceInterface;
use InvalidArgumentException;

class SkillExecutor implements SkillExecutorInterface
{
    protected static array $loadedPrompts = [];

    public function __construct(protected AIServiceInterface $aiService) {}

    public function execute(
        string $skillKey,
        array $variables = [],
        array $options = [],
    ): string {
        // --- 1. DETECT CONSOLIDATED GROUP REQUESTS ---
        if (
            str_contains($skillKey, "body_content.batch_draft_engine") ||
            str_contains($skillKey, "body_content.batch_expand_engine")
        ) {
            return $this->executeGroupedBlock($skillKey, $variables, $options);
        }

        // Standard single agent path continues identically...
        $filePath = $this->resolveFilePath($skillKey);
        $rawSystemInstructions = $this->loadFileContent($filePath);
        return $this->processLLMCall(
            $skillKey,
            $rawSystemInstructions,
            $resolvedFileSource = $filePath,
            $variables,
            $options,
            false,
        );
    }

    /**
     * Dynamically aggregates multiple individual sub-agent prompt files
     * into a single network payload.
     */
    protected function executeGroupedBlock(
        string $groupKey,
        array $variables,
        array $options,
    ): string {
        $baseKeyParts = explode(".core_writing.", $groupKey);
        $trackPrefix = $baseKeyParts[0] . ".core_writing.body_content";

        if (str_contains($groupKey, "batch_draft_engine")) {
            $subAgents = [
                "topic_sentence_opener",
                "list_bullet_generator",
                "code_technical_step_writer",
            ];
            $blockTitle = "PHASE A: CORE CONTENT DRAFTING";
        } else {
            $subAgents = [
                "callout_key_takeaway_writer",
                "glossary_definition_provider",
            ];
            $blockTitle = "PHASE B: VALUE EXTRACTION METRICS";
        }

        $aggregatedInstructions = "# Combined Processing Engine: {$blockTitle}\n";
        $aggregatedInstructions .=
            "Execute the following specialized sub-agent protocols in sequence. Build your final response step-by-step, ensuring all individual guidelines are strictly satisfied.\n\n";

        foreach ($subAgents as $agentName) {
            $targetKey = "{$trackPrefix}.{$agentName}.skills";
            try {
                $filePath = $this->resolveFilePath($targetKey);
                $content = $this->loadFileContent($filePath);
                $cleanContent = preg_replace('/^#.*$/m', "", $content);

                $aggregatedInstructions .=
                    "## Sub-Agent Protocol: " .
                    ucwords(str_replace("_", " ", $agentName)) .
                    "\n";
                $aggregatedInstructions .= trim($cleanContent) . "\n\n";
            } catch (\Exception $e) {
                continue;
            }
        }

        return $this->processLLMCall(
            $groupKey,
            $aggregatedInstructions,
            "Dynamic Aggregator: " . count($subAgents) . " micro-agents",
            $variables,
            $options,
            true,
        );
    }

    protected function resolveFilePath(string $skillKey): string
    {
        if (str_ends_with($skillKey, ".skills")) {
            $cleanSkillKey = substr($skillKey, 0, -7);
            $lastDotPos = strrpos($cleanSkillKey, ".");
            $directoryParts = substr($cleanSkillKey, 0, $lastDotPos);
            $fileName = substr($cleanSkillKey, $lastDotPos + 1);
            $relativePath =
                str_replace(".", DIRECTORY_SEPARATOR, $directoryParts) .
                DIRECTORY_SEPARATOR .
                $fileName .
                ".skills.md";
        } else {
            $relativePath =
                str_replace(".", DIRECTORY_SEPARATOR, $skillKey) . ".md";
        }

        $overridesBase = env("OVERLAY_OVERRIDES");
        if ($overridesBase) {
            $path = realpath(
                base_path($overridesBase) . DIRECTORY_SEPARATOR . $relativePath,
            );
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        $fallbackPath = app_path(
            "PncAgentics" .
                DIRECTORY_SEPARATOR .
                "Skills" .
                DIRECTORY_SEPARATOR .
                $relativePath,
        );
        if (!file_exists($fallbackPath)) {
            throw new InvalidArgumentException(
                "Agent Skill File could not be located at path: [{$fallbackPath}].",
            );
        }
        return $fallbackPath;
    }

    protected function loadFileContent(string $filePath): string
    {
        if (!isset(self::$loadedPrompts[$filePath])) {
            self::$loadedPrompts[$filePath] = file_get_contents($filePath);
        }
        return self::$loadedPrompts[$filePath];
    }

    protected function processLLMCall(
        string $skillKey,
        string $instructions,
        string $source,
        array $variables,
        array $options,
        bool $isGrouped,
    ): string {
        $configOptions = SkillOptions::fromArray($options);
        $compiledInstructions = $this->parsePlaceholders(
            $instructions,
            $variables,
        );
        $promptPayload = $this->buildPromptPayload(
            $compiledInstructions,
            $variables,
        );

        return $this->aiService->complete($promptPayload, [
            "temperature" => $configOptions->temperature,
            "max_tokens" => $configOptions->maxTokens,
            "metadata" => [
                "skill_executed" => $skillKey,
                "resolved_file" => $source,
                "is_aggregated" => $isGrouped,
            ],
        ]);
    }

    protected function parsePlaceholders(
        string $template,
        array $variables,
    ): string {
        foreach ($variables as $key => $value) {
            if (is_scalar($value)) {
                $template = preg_replace(
                    "/\{\{\s*" . preg_quote($key, "/") . "\s*\}\}/i",
                    (string) $value,
                    $template,
                );
            }
        }
        return $template;
    }

    protected function buildPromptPayload(
        string $instructions,
        array $variables,
    ): string {
        $payload =
            "=== SYSTEM ROLE & SKILL PROTOCOLS ===\n" .
            trim($instructions) .
            "\n\n=== CONTEXTUAL DATA WORKSPACE ===\n";
        foreach ($variables as $key => $value) {
            $payload .=
                is_array($value) || is_object($value)
                    ? "Data Object [{$key}]:\n" .
                        json_encode(
                            $value,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                        ) .
                        "\n"
                    : "Variable [{$key}]: {$value}\n";
        }
        $payload .=
            "\n=== EXECUTION RUN ===\nExecute the task using the constraints defined above. Output only the requested content body structure without introductory commentary.";
        return $payload;
    }
}
