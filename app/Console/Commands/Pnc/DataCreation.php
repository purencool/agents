<?php

namespace App\Console\Commands\Pnc;

use App\PncServices\Agent\Data\AgentPayloadData;
use App\PncTemplates\Actions\ReframeAgentContentAction;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DataCreation extends Command
{
    protected $signature = "pnc:agent:data:creation {data : The raw JSON string object}";
    protected $description = "Accepts a JSON payload to batch generate AI assets concurrently with automatic sequential fallback handling using abstracted template fields";

    public function __construct(
        protected ReframeAgentContentAction $reframeAction,
    ) {
        parent::__construct();
    }

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

        // Build a formatted track list string showing both Content Track and its associated Template layout
        $formattedTracks = [];
        foreach (
            $payloadData->contentTracks
            as $trackName => $templateFilename
        ) {
            $formattedTracks[] = "{$trackName} [via: {$templateFilename}]";
        }

        $this->info("Initiating AI Content Generation Action Pipeline:");
        $this->line(" • Domain: " . $payloadData->domain);
        $this->line(" • Title:  " . $payloadData->title);
        $this->line(" • Tracks Queued: " . implode(", ", $formattedTracks));

        // Compile tasks array mapping closures
        $tasks = [];
        foreach (
            $payloadData->contentTracks
            as $trackName => $templateFilename
        ) {
            $tasks[$trackName] = function () use (
                $payloadData,
                $trackName,
                $templateFilename,
            ) {
                // Pass trackName, template filename and dataset array straight into the new Action node
                $metrics = $this->reframeAction->execute(
                    $trackName,
                    $templateFilename,
                    $payloadData->toArray(),
                );

                return "Success: Track [{$trackName}] mapped via [{$templateFilename}] saved at [{$metrics["target_directory"]}].";
            };
        }

        $timeoutCeiling = (int) config("pncservices.ai.timeout", 600);

        try {
            $this->comment(
                "Attempting parallel workflow execution threads (Max process timeout: {$timeoutCeiling}s)...",
            );

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
                $this->info("  -> Parallel Thread {$statusText}");
            }
        } catch (\Throwable $e) {
            $this->warn(
                " Parallel execution unavailable or timed out: " .
                    $e->getMessage(),
            );
            $this->comment(
                "Initiating automatic fallback: Switching cleanly back to standard sequential execution processing...",
            );

            foreach ($tasks as $trackName => $taskClosure) {
                $this->comment(
                    "  -> Synchronously processing track branch: [{$trackName}]...",
                );
                try {
                    $statusText = $taskClosure();
                    $this->info("     {$statusText}");
                } catch (\Exception $subException) {
                    $this->error(
                        " ❌ Fallback Track Error [{$trackName}]: " .
                            $subException->getMessage(),
                    );
                }
            }
        }

        $totalJobDuration = round(microtime(true) - $totalJobStart, 2);

        Log::channel("daily")->info(
            " [BATCH CREATION COMPLETED] Generation processing completed in {$totalJobDuration}s",
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
