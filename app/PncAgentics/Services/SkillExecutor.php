<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Data\SkillOptions;
use App\PncServices\Contracts\AIServiceInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Executes AI-powered skill prompts by resolving markdown skill files,
 * compiling template placeholders, and delegating to an AI service.
 *
 * Supports both single-skill execution and "grouped block" execution,
 * where multiple sub-agent skill files are aggregated into a single
 * combined prompt before being sent to the LLM.
 */
class SkillExecutor implements SkillExecutorInterface
{
    /**
     * In-memory cache of loaded skill prompt files, keyed by absolute file path.
     *
     * @var array<string, string>
     */
    protected static array $loadedPrompts = [];

    /**
     * @param AIServiceInterface $aiService The AI service used to complete LLM calls.
     */
    public function __construct(protected AIServiceInterface $aiService) {}

    /**
     * Execute a skill by its dot-notation key.
     *
     * If the key matches a known "grouped block" pattern (batch_draft_engine
     * or batch_expand_engine), the method aggregates multiple sub-agent
     * skill files into a single combined prompt. Otherwise, it resolves a
     * single skill file and processes it directly.
     *
     * @param string $skillKey    Dot-notation key identifying the skill (e.g. "track.core_writing.body_content.topic_sentence_opener.skills").
     * @param array  $variables   Template variables for placeholder substitution and contextual data.
     * @param array  $options     LLM configuration options (temperature, max_tokens, etc.).
     *
     * @return string The raw LLM completion output.
     *
     * @throws InvalidArgumentException When the resolved skill file does not exist on disk.
     */
    public function execute(
        string $skillKey,
        array $variables = [],
        array $options = [],
    ): string {
        if (
            str_contains($skillKey, "body_content.batch_draft_engine") ||
            str_contains($skillKey, "body_content.batch_expand_engine")
        ) {
            return $this->executeGroupedBlock($skillKey, $variables, $options);
        }

        $filePath = $this->resolveFilePath($skillKey);
        $rawSystemInstructions = $this->loadFileContent($filePath);

        return $this->processLLMCall(
            $skillKey,
            $rawSystemInstructions,
            $filePath,
            $variables,
            $options,
            false,
        );
    }

    /**
     * Execute a grouped block by aggregating multiple sub-agent skill files
     * into a single combined prompt.
     *
     * For "batch_draft_engine" keys, aggregates Phase A sub-agents:
     *   - topic_sentence_opener
     *   - list_bullet_generator
     *   - code_technical_step_writer
     *
     * For "batch_expand_engine" keys, aggregates Phase B sub-agents:
     *   - callout_key_takeaway_writer
     *   - glossary_definition_provider
     *
     * Each sub-agent's markdown headings (# lines) are stripped before
     * inclusion to avoid duplicate top-level headers in the combined prompt.
     *
     * @param string $groupKey   The dot-notation group key.
     * @param array  $variables  Template variables for placeholder substitution.
     * @param array  $options    LLM configuration options.
     *
     * @return string The raw LLM completion output.
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
            "Execute the following specialized sub-agent protocols in sequence. " .
            "Build your final response step-by-step, ensuring all individual guidelines are strictly satisfied.\n\n";

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
                // Silently skip sub-agents whose skill files cannot be resolved.
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

    /**
     * Resolve a dot-notation skill key to an absolute file path.
     *
     * Resolution order:
     *  1. If the `OVERLAY_OVERRIDES` env var is set, check for the file
     *     under that base directory first.
     *  2. Fall back to `app/PncAgentics/Skills/...`.
     *
     * Key conventions:
     *  - Keys ending in `.skills` map to `<dir>/<name>.skills.md`
     *  - Other keys map to `<dir>/<name>.md`
     *
     * @param string $skillKey Dot-notation skill key.
     *
     * @return string Absolute path to the resolved skill file.
     *
     * @throws InvalidArgumentException When the file cannot be found at any location.
     */
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

    /**
     * Load and cache the contents of a skill file.
     *
     * Uses a static in-memory cache so that repeated reads of the same
     * file within a single request cycle only hit the filesystem once.
     *
     * @param string $filePath Absolute path to the file.
     *
     * @return string The raw file contents.
     *
     * @throws \RuntimeException When the file cannot be read.
     */
    protected function loadFileContent(string $filePath): string
    {
        if (!isset(self::$loadedPrompts[$filePath])) {
            self::$loadedPrompts[$filePath] = file_get_contents($filePath);
        }

        return self::$loadedPrompts[$filePath];
    }

    /**
     * Compile the prompt payload, invoke the AI service, and return the result.
     *
     * Builds the final prompt by combining compiled system instructions with
     * contextual variable data, then delegates to the AI service with the
     * configured temperature, max tokens, and metadata.
     *
     * Logs an error if the AI service returns a non-string value.
     *
     * @param string $skillKey     The skill key being executed (for metadata).
     * @param string $instructions Raw system instructions / prompt template.
     * @param string $source       Human-readable source label (file path or aggregator description).
     * @param array  $variables    Template variables for placeholder substitution.
     * @param array  $options      LLM configuration options.
     * @param bool   $isGrouped    Whether this is an aggregated (grouped) call.
     *
     * @return string The LLM completion output.
     */
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

        $result = $this->aiService->complete($promptPayload, [
            "temperature" => $configOptions->temperature,
            "max_tokens" => $configOptions->maxTokens,
            "system" => $compiledInstructions,
            "metadata" => [
                "skill_executed" => $skillKey,
                "resolved_file" => $source,
                "is_aggregated" => $isGrouped,
            ],
        ]);

        if (!is_string($result)) {
            Log::error("🤖 [processLLMCall] Non-string return detected", [
                "skill" => $skillKey,
                "type" => gettype($result),
                "value" => var_export($result, true),
            ]);
        }

        return $result;
    }

    /**
     * Replace `{{ key }}` placeholders in a template with scalar variable values.
     *
     * Only scalar (string, int, float, bool) values are substituted.
     * Array and object values are ignored here (they are injected separately
     * into the contextual data workspace by buildPromptPayload).
     *
     * @param string $template  The prompt template containing `{{ key }}` placeholders.
     * @param array  $variables Key-value pairs of template variables.
     *
     * @return string The template with all resolvable placeholders replaced.
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
     * Build the full prompt payload sent to the AI service.
     *
     * The payload is structured in three sections:
     *  1. SYSTEM ROLE & SKILL PROTOCOLS – the compiled instructions.
     *  2. CONTEXTUAL DATA WORKSPACE – scalar variables as key-value lines,
     *     arrays/objects as pretty-printed JSON.
     *  3. EXECUTION RUN – a fixed instruction to output only the requested content.
     *
     * @param string $instructions Compiled system instructions (placeholders already resolved).
     * @param array  $variables    All template variables (scalars and structured data).
     *
     * @return string The complete prompt payload string.
     */
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
            "\n=== EXECUTION RUN ===\nExecute the task using the constraints defined above. " .
            "Output only the requested content body structure without introductory commentary.";

        return $payload;
    }
}
