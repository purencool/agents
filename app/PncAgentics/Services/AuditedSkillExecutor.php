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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Decorator that wraps a base SkillExecutor with audit logging.
 *
 * Adds the following around every execute() call:
 *  - Entry log with run ID, skill key, and variable keys.
 *  - Detailed prompt tree written to a dynamic AI audit log file.
 *  - Delegation to the wrapped base executor.
 *  - Success log with execution time.
 *  - Performance warning if execution exceeds 45 seconds.
 *  - Detailed response tree appended to the same audit log file.
 *  - Error log + re-throw on failure.
 *
 * The audit log file path is resolved from the LOGGING env variable
 * (relative to base_path) or falls back to storage/logs/.
 *
 * Environment variable:
 *  - LOGGING (string): Relative path for the AI audit log directory.
 */
class AuditedSkillExecutor implements SkillExecutorInterface
{
    /**
     * @param SkillExecutorInterface $baseExecutor The original executor to wrap.
     */
    public function __construct(
        protected SkillExecutorInterface $baseExecutor,
    ) {}

    /**
     * Execute a skill with full audit logging.
     *
     * Logs the entry, delegates to the base executor, then logs the
     * result (or error) with timing information. Also writes detailed
     * prompt/response trees to a file-based audit log.
     *
     * @param string $skillKey   Unique identifier for the skill.
     * @param array  $variables  Template variables to pass to the skill.
     * @param array  $options    Execution options (temperature, max_tokens, etc.).
     *
     * @return string The result from the base executor.
     *
     * @throws \Exception Re-thrown from the base executor after logging.
     */
    public function execute(
        string $skillKey,
        array $variables = [],
        array $options = [],
    ): string {
        $runId = Str::uuid()->toString();
        $startTime = microtime(true);

        Log::channel("daily")->info(
            "[AI Skill Init] Run ID: {$runId} | Skill: {$skillKey}",
            [
                "variables_keys" => array_keys($variables),
                "options" => $options,
            ],
        );

        $this->logDetailedPromptTree($runId, $skillKey, $variables);

        try {
            $result = $this->baseExecutor->execute(
                $skillKey,
                $variables,
                $options,
            );

            $executionTime = round(microtime(true) - $startTime, 2);

            Log::channel("daily")->info(
                "✅ [AI Skill Success] Run ID: {$runId} | Time: {$executionTime}s",
            );

            if ($executionTime > 45.0) {
                Log::channel("daily")->warning(
                    "🚨 [PERFORMANCE WARNING] Run ID: {$runId} | Skill [{$skillKey}] took abnormally long ({$executionTime}s). Verify your local GPU compute availability, context window allocation limits, or VRAM memory states.",
                    [
                        "skill" => $skillKey,
                        "duration_seconds" => $executionTime,
                    ],
                );
            }

            $this->logDetailedResponseTree($runId, $skillKey, $result);

            return $result;
        } catch (\Exception $e) {
            $executionTime = round(microtime(true) - $startTime, 2);

            Log::channel("daily")->error(
                "[AI Skill Failure] Run ID: {$runId} | Time: {$executionTime}s | Error: {$e->getMessage()}",
                [
                    "trace" => $e->getTraceAsString(),
                ],
            );

            throw $e;
        }
    }

    /**
     * Resolve the dynamic AI audit log file path.
     *
     * Reads the LOGGING env variable as a base_path-relative directory.
     * Creates the directory if it doesn't exist. Falls back to
     * storage/logs/ when the env variable is not set.
     *
     * @return string Absolute path to today's audit log file.
     */
    protected function getDynamicLogPath(): string
    {
        $customLoggingBase = env("LOGGING");

        if ($customLoggingBase) {
            $basePathResolved = base_path($customLoggingBase);

            if (!is_dir($basePathResolved)) {
                mkdir($basePathResolved, 0755, true);
            }

            return $basePathResolved .
                DIRECTORY_SEPARATOR .
                "ai_audit_" .
                now()->format("Y-m-d") .
                ".log";
        }

        return storage_path("logs/ai_audit_" . now()->format("Y-m-d") . ".log");
    }

    /**
     * Append the input variables to the audit log file.
     *
     * Writes a formatted block containing the run ID, skill key,
     * timestamp, and all variable key/value pairs.
     *
     * @param string $runId      Unique execution run ID.
     * @param string $skillKey   The skill being executed.
     * @param array  $variables  The template variables passed to the skill.
     */
    protected function logDetailedPromptTree(
        string $runId,
        string $skillKey,
        array $variables,
    ): void {
        $logPath = $this->getDynamicLogPath();
        $divider = str_repeat("=", 80);
        $timestamp = now()->toDateTimeString();

        $logContent = <<<EOT
        {$divider}
        [{$timestamp}] [PROMPT INPUT] | RUN ID: {$runId} | SKILL: {$skillKey}
        {$divider}
        Variables Sent:
        EOT;

        foreach ($variables as $key => $value) {
            $formattedValue =
                is_array($value) || is_object($value)
                    ? json_encode(
                        $value,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    )
                    : $value;
            $logContent .= "\n--> [{$key}]:\n{$formattedValue}\n";
        }

        $logContent .= "\n\n";

        file_put_contents($logPath, $logContent, FILE_APPEND);
    }

    /**
     * Append the LLM response to the audit log file.
     *
     * @param string $runId      Unique execution run ID.
     * @param string $skillKey   The skill that produced the response.
     * @param string $result     The raw response text from the LLM.
     */
    protected function logDetailedResponseTree(
        string $runId,
        string $skillKey,
        string $result,
    ): void {
        $logPath = $this->getDynamicLogPath();
        $divider = str_repeat("-", 80);

        $logContent = <<<EOT
        {$divider}
        [RUN ID: {$runId}] [LLM RESPONSE OUTPUT] | SKILL: {$skillKey}
        {$divider}
        {$result}
        ================================================================================


        EOT;

        file_put_contents($logPath, $logContent, FILE_APPEND);
    }
}
