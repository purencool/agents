<?php

namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\SkillExecutorInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuditedSkillExecutor implements SkillExecutorInterface
{
    /**
     * Pass the original implementation through Constructor Dependency Injection.
     */
    public function __construct(
        protected SkillExecutorInterface $baseExecutor,
    ) {}

    public function execute(
        string $skillKey,
        array $variables = [],
        array $options = [],
    ): string {
        $runId = Str::uuid()->toString();
        $startTime = microtime(true);

        // Audit the entry checkpoint into the standard
        // Laravel daily logging stream
        Log::channel("daily")->info(
            "🤖 [AI Skill Init] Run ID: {$runId} | Skill: {$skillKey}",
            [
                "variables_keys" => array_keys($variables),
                "options" => $options,
            ],
        );

        // Capture the fully compiled layout parameters into your
        // dynamic external log directory
        $this->logDetailedPromptTree($runId, $skillKey, $variables);

        try {
            // Pass execution through seamlessly to the base SkillExecutor
            $result = $this->baseExecutor->execute(
                $skillKey,
                $variables,
                $options,
            );

            $executionTime = round(microtime(true) - $startTime, 2);

            // Audit successful completion and performance metrics
            Log::channel("daily")->info(
                "✅ [AI Skill Success] Run ID: {$runId} | Time: {$executionTime}s",
            );

            // Trigger an explicit warning if compute/network times exceed 45 seconds
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
                "❌ [AI Skill Failure] Run ID: {$runId} | Time: {$executionTime}s | Error: {$e->getMessage()}",
                [
                    "trace" => $e->getTraceAsString(),
                ],
            );

            throw $e;
        }
    }

    /**
     * Helper method to resolve the dynamic file logging path out of the environment.
     */
    protected function getDynamicLogPath(): string
    {
        // Reads from your exact LOGGING environment variable
        $customLoggingBase = env("LOGGING");

        if ($customLoggingBase) {
            $basePathResolved = base_path($customLoggingBase);

            // Auto-create the target logs folder structure if it
            // doesn't exist yet outside your repo
            if (!is_dir($basePathResolved)) {
                mkdir($basePathResolved, 0755, true);
            }

            return $basePathResolved .
                DIRECTORY_SEPARATOR .
                "ai_audit_" .
                now()->format("Y-m-d") .
                ".log";
        }

        // Fallback baseline standard repository pathway
        return storage_path("logs/ai_audit_" . now()->format("Y-m-d") . ".log");
    }

    /**
     * Writes out the exact input parameters and
     * context to a dedicated AI debug log file.
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
     * Appends the finalized raw content response from the LLM into the same
     * run ID block.
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
