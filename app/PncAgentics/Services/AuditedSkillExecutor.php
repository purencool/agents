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
 * Decorator that wraps a base SkillExecutor with per-run audit logging.
 *
 * For every execute() call this decorator:
 *  1. Generates a unique run ID (UUID v4).
 *  2. Writes a one-line START entry to the daily log channel.
 *  3. Writes a formatted PROMPT INPUT block to a per-run audit log file.
 *  4. Delegates to the wrapped base executor.
 *  5. On success: writes a DONE entry + LLM RESPONSE block to the audit file.
 *  6. On failure: writes a FAIL entry + ERROR OUTPUT block, then re-throws.
 *  7. If execution exceeds 45 seconds, logs a performance warning.
 *
 * Audit log file structure:
 *   {base}/YYYY-MM-DD_Hi/{domain}/{runId}.log
 *
 *   Where {base} is resolved from the LOGGING env variable (relative to
 *   base_path) or falls back to storage/logs/.
 *
 * Example:
 *   storage/logs/2026-11-25_2042/example.com/55bdde90-802d-4fcb-a4e0-21821219632a.log
 *
 * Environment variables:
 *  - LOGGING (string): Relative path for the audit log base directory.
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
     * Lifecycle: START → PROMPT INPUT → [base executor] → DONE/FAIL → RESPONSE
     *
     * @param string $skillKey  Unique dot-notation identifier for the skill.
     * @param array  $variables Template variables passed to the skill.
     * @param array  $options   Execution options. Must include "_domain" (string)
     *                          for log directory organisation. Other keys
     *                          (temperature, max_tokens) are forwarded to the
     *                          base executor.
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
        $domain = $options["_domain"] ?? "unknown";
        $startTime = microtime(true);
        $startedAt = now()->toDateTimeString();

        // ─── DAILY LOG: Entry ─────────────────────────────────────────────
        Log::channel("daily")->info(
            "[AI] START | {$runId} | skill={$skillKey} | domain={$domain} | vars=" .
                count($variables) .
                " | " .
                $startedAt,
            [
                "run_id" => $runId,
                "skill" => $skillKey,
                "domain" => $domain,
                "variables_keys" => array_keys($variables),
                "options" => $options,
            ],
        );

        // ─── AUDIT FILE: Prompt input ─────────────────────────────────────
        $this->logDetailedPromptTree(
            $runId,
            $skillKey,
            $variables,
            $startedAt,
            $domain,
        );

        try {
            $result = $this->baseExecutor->execute(
                $skillKey,
                $variables,
                $options,
            );

            $executionTime = round(microtime(true) - $startTime, 2);
            $finishedAt = now()->toDateTimeString();

            // ─── DAILY LOG: Success ───────────────────────────────────────
            Log::channel("daily")->info(
                "[AI] DONE  | {$runId} | skill={$skillKey} | domain={$domain} | took={$executionTime}s | {$finishedAt}",
            );

            if ($executionTime > 45.0) {
                Log::channel("daily")->warning(
                    "[AI] ⚠ SLOW  | {$runId} | skill={$skillKey} | domain={$domain} | took={$executionTime}s (threshold: 45s) | {$finishedAt}",
                    [
                        "run_id" => $runId,
                        "skill" => $skillKey,
                        "domain" => $domain,
                        "duration_seconds" => $executionTime,
                    ],
                );
            }

            // ─── AUDIT FILE: Response ─────────────────────────────────────
            $this->logDetailedResponseTree(
                $runId,
                $skillKey,
                $result,
                $executionTime,
                $finishedAt,
                false,
                $domain,
            );

            return $result;
        } catch (\Exception $e) {
            $executionTime = round(microtime(true) - $startTime, 2);
            $failedAt = now()->toDateTimeString();

            // ─── DAILY LOG: Failure ───────────────────────────────────────
            Log::channel("daily")->error(
                "[AI] FAIL  | {$runId} | skill={$skillKey} | domain={$domain} | took={$executionTime}s | error=\"{$e->getMessage()}\" | {$failedAt}",
                [
                    "run_id" => $runId,
                    "skill" => $skillKey,
                    "domain" => $domain,
                    "exception" => $e::class,
                    "message" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ],
            );

            // ─── AUDIT FILE: Error ────────────────────────────────────────
            $this->logDetailedResponseTree(
                $runId,
                $skillKey,
                "ERROR: " . $e->getMessage(),
                $executionTime,
                $failedAt,
                true,
                $domain,
            );

            throw $e;
        }
    }

    /**
     * Resolve the per-run audit log file path.
     *
     * Directory structure: {base}/YYYY-MM-DD_Hi/{domain}/{runId}.log
     *
     * The base directory is read from the LOGGING env variable (resolved
     * relative to base_path). Falls back to storage/logs/ when not set.
     *
     * @param string $runId  UUID for this execution run.
     * @param string $domain The domain this run belongs to (used as directory name).
     *
     * @return string Absolute path to the audit log file for this run.
     */
    protected function getDynamicLogPath(string $runId, string $domain): string
    {
        $base = env("LOGGING")
            ? base_path(env("LOGGING"))
            : storage_path("logs");

        $dir =
            $base .
            DIRECTORY_SEPARATOR .
            now()->format("Y-m-d_Hi") .
            DIRECTORY_SEPARATOR .
            $domain;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . DIRECTORY_SEPARATOR . "{$runId}.log";
    }

    /**
     * Write the formatted prompt input block to the per-run audit log file.
     *
     * Renders all template variables with proper indentation for
     * multi-line values (arrays/objects are JSON pretty-printed).
     *
     * @param string $runId      UUID for this execution run.
     * @param string $skillKey   The skill being executed.
     * @param array  $variables  Template variables passed to the skill.
     * @param string $startedAt  Human-readable timestamp of the call.
     * @param string $domain     Domain identifier (for file path).
     */
    protected function logDetailedPromptTree(
        string $runId,
        string $skillKey,
        array $variables,
        string $startedAt,
        string $domain,
    ): void {
        $logPath = $this->getDynamicLogPath($runId, $domain);
        $bar = str_repeat("═", 72);

        $lines = [
            "",
            "┌{$bar}┐",
            "│  📥 PROMPT INPUT",
            "│  Time:    {$startedAt}",
            "│  Run ID:  {$runId}",
            "│  Skill:   {$skillKey}",
            "│  Domain:  {$domain}",
            "├{$bar}┤",
            "│",
        ];

        foreach ($variables as $key => $value) {
            $formatted =
                is_array($value) || is_object($value)
                    ? json_encode(
                        $value,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    )
                    : (string) $value;

            $lines[] = "│  ▸ {$key}:";
            $lines[] = preg_replace("/^/m", "│    ", $formatted);
            $lines[] = "│";
        }

        $lines[] = "└{$bar}┘";
        $lines[] = "";

        file_put_contents($logPath, implode("\n", $lines), FILE_APPEND);
    }

    /**
     * Write the formatted LLM response (or error) block to the per-run audit log file.
     *
     * @param string $runId         UUID for this execution run.
     * @param string $skillKey      The skill that produced the response.
     * @param string $result        The raw response text (or error message).
     * @param float  $executionTime Duration in seconds.
     * @param string $finishedAt    Human-readable timestamp of completion.
     * @param bool   $isError       Whether this block represents a failure.
     * @param string $domain        Domain identifier (for file path).
     */
    protected function logDetailedResponseTree(
        string $runId,
        string $skillKey,
        string $result,
        float $executionTime,
        string $finishedAt,
        bool $isError = false,
        string $domain = "unknown",
    ): void {
        $logPath = $this->getDynamicLogPath($runId, $domain);
        $bar = str_repeat("═", 72);

        $icon = $isError ? "❌ ERROR OUTPUT" : "📤 LLM RESPONSE";
        $status = $isError ? "FAILED" : "OK";

        $lines = [
            "┌{$bar}┐",
            "│  {$icon}",
            "│  Time:    {$finishedAt}",
            "│  Run ID:  {$runId}",
            "│  Skill:   {$skillKey}",
            "│  Domain:  {$domain}",
            "│  Status:  {$status} | Duration: {$executionTime}s",
            "├{$bar}┤",
            "│",
            preg_replace("/^/m", "│  ", $result),
            "│",
            "└{$bar}┘",
            "",
        ];

        file_put_contents($logPath, implode("\n", $lines), FILE_APPEND);
    }
}
