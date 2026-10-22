<?php

namespace App\Console\Commands\Pnc;

use App\PncServices\Agent\Data\AgentPayloadData;
use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use App\PncServices\Contracts\AgentStoreInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DataCreation extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = "pnc:agent:data:creation {data : The raw JSON string object}";

    /**
     * The console command description.
     */
    protected $description = "Accepts a JSON payload to batch generate AI assets concurrently with automatic sequential fallback handling using abstracted validation structures";

    /**
     * Execute the console command.
     */
    public function handle(
        WorkflowFactoryInterface $workflowFactory,
        AgentStoreInterface $agentStore,
    ): int {
        // --- 1. START THE TOTAL RUNTIME TRACKING TIMER ---
        $totalJobStart = microtime(true);

        try {
            // --- DELEGATE PARSING AND VALIDATION COMPLETELY TO DTO LAYER ---
            $payloadData = AgentPayloadData::fromJsonString(
                $this->argument("data"),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Initiating AI Content Generation Action Pipeline:");
        $this->line(" • Domain: " . $payloadData->domain);
        $this->line(" • Title:  " . $payloadData->title);
        $this->line(
            " • AI Tracks Queued: " .
                implode(", ", $payloadData->contentTracks),
        );
        $this->line(str_repeat("=", 80));

        // Assemble the tasks mapping closures array using the strongly-typed DTO values
        $tasks = [];
        foreach ($payloadData->contentTracks as $trackType) {
            $tasks[$trackType] = function () use (
                $payloadData,
                $trackType,
                $workflowFactory,
                $agentStore,
            ) {
                $trackPayload = [
                    "domain" => $payloadData->domain,
                    "type" => $payloadData->type,
                    "title" => $payloadData->title,
                    "description" => $payloadData->description,
                    "content_track" => $trackType,
                ];

                // Scaffold directory structures tree node safely
                $targetDirectory = $agentStore->createStructure($trackPayload);

                // Query factory router to load the central documentation workflow instance
                $resolved = $workflowFactory->make(
                    $trackType,
                    $isUpdate = false,
                );
                $workflow = $resolved["workflow"];
                $baseSkillKey = $resolved["base_key"];

                // Execute the unified parent agent processing pipeline
                $markdownResult = $workflow->execute(
                    $baseSkillKey,
                    $trackPayload,
                    $targetDirectory,
                );

                // Commit output files cleanly across all targeted production channels
                file_put_contents(
                    "{$targetDirectory}/master.md",
                    $markdownResult,
                );

                if (is_dir("{$targetDirectory}/dev")) {
                    file_put_contents(
                        "{$targetDirectory}/dev/master.md",
                        $markdownResult,
                    );
                }
                if (is_dir("{$targetDirectory}/stage")) {
                    file_put_contents(
                        "{$targetDirectory}/stage/master.md",
                        $markdownResult,
                    );
                }

                return "✔ Success: Track [{$trackType}] completed at [{$targetDirectory}].";
            };
        }

        // Determine the dynamic systemic timeout ceiling out of your environment settings
        $timeoutCeiling = (int) env("AI_REQUEST_TIMEOUT", 600);

        try {
            $this->comment(
                "🚀 Attempting parallel workflow execution threads (Max process timeout: {$timeoutCeiling}s)...",
            );

            // Check if the framework environment possesses native concurrency driver capabilities
            if (config("concurrency.default") === "sync") {
                throw new \RuntimeException(
                    "Concurrency engine driver is set to sync. Defaulting to sequential mode.",
                );
            }

            // --- 2. EXTENDED RUNTIME CONCURRENCY VIA RUN OPTION ARRAY PARAMETER ---
            $results = Concurrency::driver("fork")->run(
                $tasks,
                $timeoutCeiling,
            );

            foreach ($results as $track => $statusText) {
                $this->info("  -> Parallel Thread {$statusText}");
            }
        } catch (\Throwable $e) {
            // --- 3. AUTOMATIC SEQUENTIAL FALLBACK LAYER PASSTHROUGH ---
            $this->warn(
                "  ⚠ Parallel execution unavailable or timed out: " .
                    $e->getMessage(),
            );
            $this->comment(
                "🔄 Initiating automatic fallback: Switching cleanly back to standard sequential execution processing...",
            );

            foreach ($tasks as $trackType => $taskClosure) {
                $this->comment(
                    "  -> Synchronously processing track branch: [{$trackType}]...",
                );
                try {
                    $statusText = $taskClosure();
                    $this->info("     {$statusText}");
                } catch (\Exception $subException) {
                    $this->error(
                        "     ❌ Fallback Track Error [{$trackType}]: " .
                            $subException->getMessage(),
                    );
                }
            }
        }

        // --- 4. CALCULATE AND COMMIT TOTAL RUNTIME TRANSITS TO LOG FILE ---
        $totalJobDuration = round(microtime(true) - $totalJobStart, 2);

        Log::channel("daily")->info(
            "🏆 [BATCH CREATION COMPLETED] Generation processing completed in {$totalJobDuration}s",
            [
                "domain" => $payloadData->domain,
                "title" => $payloadData->title,
                "tracks_processed" => $payloadData->contentTracks,
            ],
        );

        $this->line(str_repeat("=", 80));
        $this->info(
            "✨ Batch documentation creation workflow pipeline completed successfully in {$totalJobDuration}s!",
        );
        return self::SUCCESS;
    }
}
