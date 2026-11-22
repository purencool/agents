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
use App\PncAgentics\Contracts\AgentServiceInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Executes AI-powered skill prompts by resolving markdown skill files,
 * compiling template placeholders, and delegating to the agent service.
 *
 * Request model:
 *  Each LLM call is constructed as a structured array with explicit keys:
 *
 *    [
 *        "system_prompt" => string,   // Skill file content (role, protocol, rules)
 *        "user_prompt"   => string,   // Data payload (variables, context to process)
 *        "temperature"   => float,    // Sampling temperature (0.0 – 2.0)
 *        "max_tokens"    => int,      // Maximum response tokens
 *        "metadata"      => array,    // Arbitrary context for logging/tracing
 *    ]
 *
 *  The system prompt defines HOW the model behaves (stable, per-skill).
 *  The user prompt contains WHAT the model operates on (variable data).
 *
 * Skill config shape (array passed to processLLMCall):
 *  - "name"                => string  (display name)
 *  - "system_instructions" => string  (the prompt template with {{key}} placeholders)
 *  - "model"               => ?string (optional model override)
 *  - "temperature"         => ?float  (optional default temperature)
 *  - "token_limits"        => ?int    (optional default max_tokens)
 *
 * Supports both single-skill execution and "grouped block" execution,
 * where multiple sub-agent skill files are aggregated into a single
 * combined system prompt before being sent to the LLM.
 *
 * @example
 *   $executor = app(SkillExecutor::class);
 *   $output = $executor->execute("track.core_writing.body_content.topic_sentence_opener.skills", [
 *       "topic" => "PHP Testing",
 *   ]);
 */
class SkillExecutor implements SkillExecutorInterface
{
    /**
     * In-memory cache of loaded skill prompt files, keyed by absolute file path.
     *
     * Prevents repeated filesystem reads within a single request cycle.
     *
     * @var array<string, string>
     */
    protected static array $loadedPrompts = [];

    /**
     * @param AgentServiceInterface $aiService The AI service used to complete LLM calls.
     */
    public function __construct(protected AgentServiceInterface $aiService) {}

    /**
     * Execute a skill by its dot-notation key.
     *
     * Resolution flow:
     *  1. If the key matches a grouped-block pattern (batch_draft_engine or
     *     batch_expand_engine), delegate to executeGroupedBlock() which
     *     aggregates multiple sub-agent files into one combined prompt.
     *  2. Otherwise, resolve a single skill file, load its contents, and
     *     pass them as a skill config array to processLLMCall().
     *
     * @param string $skillKey  Dot-notation key identifying the skill
     *                          (e.g. "track.core_writing.body_content.topic_sentence_opener.skills").
     * @param array  $variables Template variables for placeholder substitution
     *                          and contextual data workspace.
     * @param array  $options   Caller-provided LLM configuration overrides
     *                          (temperature, max_tokens, etc.).
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
            [
                "name" => basename($skillKey),
                "system_instructions" => $rawSystemInstructions,
            ],
            $filePath,
            $variables,
            $options,
            false,
        );
    }

    /**
     * Execute a grouped block by aggregating multiple sub-agent skill files
     * into a single combined system prompt.
     *
     * Sub-agent mapping:
     *  - "batch_draft_engine"  → Phase A: topic_sentence_opener,
     *    list_bullet_generator, code_technical_step_writer
     *  - "batch_expand_engine" → Phase B: callout_key_takeaway_writer,
     *    glossary_definition_provider
     *
     * Each sub-agent's top-level markdown headings (# lines) are stripped
     * before inclusion to avoid duplicate headers in the combined prompt.
     * Sub-agents whose files cannot be resolved are silently skipped.
     *
     * @param string $groupKey  The dot-notation group key.
     * @param array  $variables Template variables for placeholder substitution.
     * @param array  $options   LLM configuration options.
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
            } catch (\Exception) {
                // Silently skip sub-agents whose skill files cannot be resolved.
                continue;
            }
        }

        return $this->processLLMCall(
            $groupKey,
            [
                "name" => "Aggregated: {$blockTitle}",
                "system_instructions" => $aggregatedInstructions,
            ],
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
     *  1. OVERLAY_OVERRIDES env var — if set, check under that base directory
     *     first (allows integration teams to override individual skills).
     *  2. app/PncAgentics/Skills/ — the default baked-in location.
     *
     * Key conventions:
     *  - Keys ending in ".skills" map to `<dir>/<name>.skills.md`
     *  - Other keys map to `<dir>/<name>.md`
     *  - Dots are converted to directory separators.
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
     * Uses a static in-memory cache so that repeated reads of the same file
     * within a single request cycle only hit the filesystem once.
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
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new \RuntimeException(
                    "Unable to read skill file: {$filePath}",
                );
            }
            self::$loadedPrompts[$filePath] = $content;
        }

        return self::$loadedPrompts[$filePath];
    }

    /**
     * Compile the prompt payload, invoke the agent service, and return the result.
     *
     * Resolves LLM parameters from two sources (in priority order):
     *  1. Caller-provided $options (per-request overrides).
     *  2. Skill config defaults (temperature, token_limits from the JSON file).
     *
     * The request array passed to AgentServiceInterface::complete() contains:
     *  - "system_prompt": The compiled system_instructions (placeholders resolved).
     *  - "user_prompt":   The contextual data workspace (variables only).
     *  - "temperature":   Resolved temperature (only if non-null).
     *  - "max_tokens":    Resolved token limit (only if non-null).
     *  - "metadata":      Application-level context for logging/tracing.
     *
     * @param string $skillKey     The skill key being executed (for metadata).
     * @param array  $skillConfig  The skill configuration array.
     *                             Expected keys:
     *                             - "name"                (string)
     *                             - "system_instructions" (string, prompt template)
     *                             - "model"               (?string)
     *                             - "temperature"         (?float)
     *                             - "token_limits"        (?int)
     * @param string $source       Human-readable source label (file path or aggregator description).
     * @param array  $variables    Template variables for placeholder substitution.
     * @param array  $options      Caller-provided LLM configuration overrides.
     * @param bool   $isGrouped    Whether this is an aggregated (grouped) call.
     *
     * @return string The LLM completion output.
     *
     * @throws \RuntimeException When the agent service returns a non-string value.
     */
    protected function processLLMCall(
        string $skillKey,
        array $skillConfig,
        string $source,
        array $variables,
        array $options,
        bool $isGrouped,
    ): string {
        $compiledInstructions = $this->parsePlaceholders(
            $skillConfig["system_instructions"] ?? "",
            $variables,
        );

        $promptPayload = $this->buildPromptPayload(
            $compiledInstructions,
            $variables,
        );

        $temperature = isset($options["temperature"])
            ? (float) $options["temperature"]
            : ($skillConfig["temperature"] ?? "" ?:
            null);

        $maxTokens = isset($options["max_tokens"])
            ? (int) $options["max_tokens"]
            : ($skillConfig["token_limits"] ?? "" ?:
            null);

        $request = [
            "system_prompt" => $compiledInstructions,
            "user_prompt" => $promptPayload,
            "metadata" => [
                "skill_executed" => $skillKey,
                "resolved_file" => $source,
                "is_aggregated" => $isGrouped,
                "skill_model" => $skillConfig["model"] ?? "" ?: null,
            ],
        ];

        if ($temperature !== null) {
            $request["temperature"] = $temperature;
        }
        if ($maxTokens !== null) {
            $request["max_tokens"] = $maxTokens;
        }

        $result = $this->aiService->complete($request);

        if (!is_string($result)) {
            throw new \RuntimeException(
                "SkillExecutor::processLLMCall — non-string return from agent service " .
                    "for skill [{$skillKey}] (got " .
                    gettype($result) .
                    ")",
            );
        }

        return $result;
    }
    /**
     * Replace `{{ key }}` placeholders in a template with scalar variable values.
     *
     * Only scalar (string, int, float, bool) values are substituted.
     * Array and object values are left untouched here — they are injected
     * separately into the contextual data workspace by buildPromptPayload().
     *
     * @param string $template  The prompt template containing `{{ key }}` placeholders.
     * @param array  $variables Key-value pairs of template variables.
     *
     * @return string The template with all resolvable scalar placeholders replaced.
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
     * Build the full prompt payload sent as the user_prompt to the agent service.
     *
     * The payload is structured in three clearly delimited sections:
     *
     *  1. === SYSTEM ROLE & SKILL PROTOCOLS ===
     *     The compiled instructions (placeholders already resolved).
     *
     *  2. === CONTEXTUAL DATA WORKSPACE ===
     *     Scalar variables rendered as "Variable [key]: value" lines.
     *     Arrays/objects rendered as "Data Object [key]:" followed by
     *     pretty-printed JSON.
     *
     *  3. === EXECUTION RUN ===
     *     A fixed instruction telling the model to output only the requested
     *     content without introductory commentary.
     *
     * @param string $instructions Compiled system instructions (placeholders resolved).
     * @param array  $variables    All template variables (scalars and structured data).
     *
     * @return string The complete three-section prompt payload.
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
