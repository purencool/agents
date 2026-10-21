<?php

namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Data\SkillOptions;
use App\PncServices\Contracts\AIServiceInterface;
use InvalidArgumentException;

class SkillExecutor implements SkillExecutorInterface
{
    /**
     * In-memory static cache container to shield local machine environments
     * from repetitive disk I/O.
     */
    protected static array $loadedPrompts = [];

    public function __construct(protected AIServiceInterface $aiService) {}

    public function execute(
        string $skillKey,
        array $variables = [],
        array $options = [],
    ): string {
        // Convert the dot notation key into the standard relative filename path
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

        // Initialize content tracking vectors
        $prependContent = "";
        $baseSystemInstructions = "";
        $resolvedFileSource = "";

        $prependsBase = env("OVERLAY_PREPENDS");
        if ($prependsBase) {
            $prependsBaseResolved = base_path($prependsBase);
            $prependFilePath = realpath(
                $prependsBaseResolved . DIRECTORY_SEPARATOR . $relativePath,
            );

            if ($prependFilePath && file_exists($prependFilePath)) {
                // If the file is not in our static cache array yet, read it from disk
                if (!isset(self::$loadedPrompts[$prependFilePath])) {
                    self::$loadedPrompts[$prependFilePath] = file_get_contents(
                        $prependFilePath,
                    );
                }
                $prependContent =
                    self::$loadedPrompts[$prependFilePath] . "\n\n";
            }
        }

        $overridesBase = env("OVERLAY_OVERRIDES");
        if ($overridesBase) {
            $overridesBaseResolved = base_path($overridesBase);
            $overrideFilePath = realpath(
                $overridesBaseResolved . DIRECTORY_SEPARATOR . $relativePath,
            );

            if ($overrideFilePath && file_exists($overrideFilePath)) {
                // Read from cache, or read from disk once and save to cache
                if (!isset(self::$loadedPrompts[$overrideFilePath])) {
                    self::$loadedPrompts[$overrideFilePath] = file_get_contents(
                        $overrideFilePath,
                    );
                }
                $baseSystemInstructions =
                    self::$loadedPrompts[$overrideFilePath];
                $resolvedFileSource = $overrideFilePath;
            }
        }

        if (empty($baseSystemInstructions)) {
            $fallbackPath = app_path(
                "PncAgentics" .
                    DIRECTORY_SEPARATOR .
                    "Skills" .
                    DIRECTORY_SEPARATOR .
                    $relativePath,
            );

            if (!file_exists($fallbackPath)) {
                throw new InvalidArgumentException(
                    "Neuro-Laravel Agent Skill File could not be located at path: [{$fallbackPath}]. Check your file casing or namespace.",
                );
            }

            // Read fallback baseline from cache, or hit disk once and store it
            if (!isset(self::$loadedPrompts[$fallbackPath])) {
                self::$loadedPrompts[$fallbackPath] = file_get_contents(
                    $fallbackPath,
                );
            }
            $baseSystemInstructions = self::$loadedPrompts[$fallbackPath];
            $resolvedFileSource = $fallbackPath;
        }

        // Merge the layers together. Prepend information smoothly stitches to the top of either source.
        $rawSystemInstructions = $prependContent . $baseSystemInstructions;

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
                "resolved_file" => $resolvedFileSource,
                "has_prepended" => !empty($prependContent),
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
