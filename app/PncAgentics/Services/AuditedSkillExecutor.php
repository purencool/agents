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

        // 1. Audit the entry checkpoint and save variables context
        Log::channel("daily")->info(
            "🤖 [AI Skill Init] Run ID: {$runId} | Skill: {$skillKey}",
            [
                "variables_keys" => array_keys($variables),
                "options" => $options,
            ],
        );

        // We can capture the fully compiled layout by reflecting into the base executor
        // or leveraging the unified runtime variables payload array tracking here.
        $this->logDetailedPromptTree($runId, $skillKey, $variables);

        try {
            // 2. Pass execution through seamlessly to the base SkillExecutor
            $result = $this->baseExecutor->execute(
                $skillKey,
                $variables,
                $options,
            );

            $executionTime = round(microtime(true) - $startTime, 2);

            // 3. Audit successful completion and performance metrics
            Log::channel("daily")->info(
                "✅ [AI Skill Success] Run ID: {$runId} | Time: {$executionTime}s",
            );

            $this->logDetailedResponseTree($runId, $skillKey, $result);

            return $result;
        } catch (\Exception $e) {
            $executionTime = round(microtime(true) - $startTime, 2);

            // Audit execution anomalies clearly with tracking points
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
     * Writes out the exact input parameters and context to a dedicated AI debug log file.
     */
    protected function logDetailedPromptTree(
        string $runId,
        string $skillKey,
        array $variables,
    ): void {
        $logPath = storage_path(
            "logs/ai_audit_" . now()->format("Y-m-d") . ".log",
        );

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
     * Appends the finalized raw content response from the LLM into the same run ID block.
     */
    protected function logDetailedResponseTree(
        string $runId,
        string $skillKey,
        string $result,
    ): void {
        $logPath = storage_path(
            "logs/ai_audit_" . now()->format("Y-m-d") . ".log",
        );
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
