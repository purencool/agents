<?php

namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Data\SkillOptions;
use App\PncServices\Contracts\AIServiceInterface;
use InvalidArgumentException;
use Illuminate\Support\Str;

class SkillExecutor implements SkillExecutorInterface
{
    public function __construct(protected AIServiceInterface $aiService) {}

    public function execute(
        string $skillKey,
        array $variables = [],
        array $options = [],
    ): string {
        // Convert the dot notation back into physical directory slashes
        $relativePath = str_replace(".", DIRECTORY_SEPARATOR, $skillKey);

        // Build the absolute physical file system path pointing to the .md file
        $filePath = app_path(
            "PncAgentics" .
                DIRECTORY_SEPARATOR .
                "Skills" .
                DIRECTORY_SEPARATOR .
                $relativePath .
                ".md",
        );

        // Verify the target markdown file physically exists on disk
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException(
                "Neuro-Laravel Agent Skill File could not be located at path: [{$filePath}]. Check your file casing or namespace.",
            );
        }

        // Read the text instructions directly from the file canvas
        $rawSystemInstructions = file_get_contents($filePath);

        $configOptions = SkillOptions::fromArray($options);

        // Variable Parsing Layer: Find and swap out explicit markers like {{title}}
        $compiledInstructions = $this->parsePlaceholders(
            $rawSystemInstructions,
            $variables,
        );

        // Isolated Context Injection Structuring
        $promptPayload = $this->buildPromptPayload(
            $compiledInstructions,
            $variables,
        );

        // Trigger the Core LLM Service with parsed runtime structures
        return $this->aiService->complete($promptPayload, [
            "temperature" => $configOptions->temperature,
            "max_tokens" => $configOptions->maxTokens,
            "metadata" => [
                "skill_executed" => $skillKey,
            ],
        ]);
    }

    /**
     * Replaces shortcode placeholder notation tags within the skills.md file strings.
     */
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

    /**
     * Assembles a structural payload that keeps instructions distinct from workspace variables.
     */
    protected function buildPromptPayload(
        string $instructions,
        array $variables,
    ): string {
        $payload = "=== SYSTEM ROLE & SKILL PROTOCOLS ===\n";
        $payload .= trim($instructions) . "\n\n";
        $payload .= "=== CONTEXTUAL DATA WORKSPACE ===\n";

        foreach ($variables as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $payload .=
                    "Data Object [{$key}]:\n" .
                    json_encode(
                        $value,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    ) .
                    "\n";
            } else {
                $payload .= "Variable [{$key}]: {$value}\n";
            }
        }

        $payload .= "\n=== EXECUTION RUN ===\n";
        $payload .=
            "Execute the task using the constraints defined above. Output only the requested content body structure without introductory commentary.";

        return $payload;
    }
}
