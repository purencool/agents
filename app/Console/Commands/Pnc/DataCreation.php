<?php

namespace App\Console\Commands\Pnc;

use App\PncServices\Agent\Data\AgentPayloadData;
use App\PncServices\Agent\Actions\ReframeAgentContentAction;
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
     * Inject ReframeAgentContentAction into the constructor.
     */
    public function __construct(
        protected ReframeAgentContentAction $reframeAction,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $totalJobStart = microtime(true);

        try {
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
            $tasks[$trackType] = function () use ($payloadData, $trackType) {
                // Execute the abstracted action wrapper directly
                $metrics = $this->reframeAction->execute(
                    $trackType,
                    $payloadData->toArray(),
                );

                return "Success: Track [{$trackType}] saved cleanly at [{$metrics["target_directory"]}/master.md] via key [{$metrics["base_skill_key"]}].";
            };
        }

        // Determine the dynamic systemic timeout ceiling out of your configuration settings
        $timeoutCeiling = (int) config("pncservices.ai.timeout", 600);

        try {
            $this->comment(
                "Attempting parallel workflow execution threads (Max process timeout: {$timeoutCeiling}s)...",
            );

            // Check if the framework environment possesses native concurrency driver capabilities
            if (config("concurrency.default") === "sync") {
                throw new \RuntimeException(
                    "Concurrency engine driver is set to sync. Defaulting to sequential mode.",
                );
            }

            $results = Concurrency::driver("fork")->run(
                $tasks,
                $timeoutCeiling,
            );

            foreach ($results as $track => $statusText) {
                $this->info(" -> Parallel Thread {$statusText}");
            }
        } catch (\Throwable $e) {
            $this->warn(
                " Parallel execution unavailable or timed out: " .
                    $e->getMessage(),
            );
            $this->comment(
                "Initiating automatic fallback: Switching cleanly back to standard sequential execution processing...",
            );

            foreach ($tasks as $trackType => $taskClosure) {
                $this->comment(
                    " -> Synchronously processing track branch: [{$trackType}]...",
                );
                try {
                    $statusText = $taskClosure();
                    $this->info("  {$statusText}");
                } catch (\Exception $subException) {
                    $this->error(
                        "     ❌ Fallback Track Error [{$trackType}]: " .
                            $subException->getMessage(),
                    );
                }
            }
        }

        $totalJobDuration = round(microtime(true) - $totalJobStart, 2);

        Log::channel("daily")->info(
            "BATCH CREATION COMPLETED] Generation processing completed in {$totalJobDuration}s",
            [
                "domain" => $payloadData->domain,
                "title" => $payloadData->title,
                "tracks_processed" => $payloadData->contentTracks,
            ],
        );

        $this->line(str_repeat("=", 80));
        $this->info(
            " Batch documentation creation workflow pipeline completed successfully in {$totalJobDuration}s!",
        );
        return self::SUCCESS;
    }
}
