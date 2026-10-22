<?php

namespace App\Console\Commands\Pnc;

use App\PncServices\Agent\Data\AgentPayloadData;
use App\PncServices\Agent\Actions\ReframeAgentContentAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DataUpdate extends Command
{
    protected $signature = "pnc:agent:data:update {data : The raw JSON string object}";
    protected $description = "Accepts a JSON payload to batch reframe, rewrite, and update existing target content track files using abstracted validation structures";

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

        $this->info("Initiating AI Content Reframing Action Pipeline:");
        $this->line(" • Domain: " . $payloadData->domain);
        $this->line(" • Title:  " . $payloadData->title);
        $this->line(str_repeat("=", 80));

        // Iterate across the requested content track threads using strongly-typed properties
        foreach ($payloadData->contentTracks as $trackType) {
            $this->comment(
                "🤖 Processing refinement updates for track branch: [{$trackType}]",
            );

            try {
                // Pass array variant straight down into legacy backend storage engine loop nodes safely
                $metrics = $this->reframeAction->execute(
                    $trackType,
                    $payloadData->toArray(),
                );

                if ($metrics["version_count"] > 0) {
                    $this->comment(
                        " Archived historical snapshot backup saved cleanly at: master-v{$metrics["version_count"]}.md",
                    );
                }
                $this->info(
                    " Injecting history context into refinery track via: {$metrics["base_skill_key"]}",
                );
                $this->info(
                    " Success: Reframed content file saved cleanly at: {$metrics["target_directory"]}/master.md",
                );
            } catch (InvalidArgumentException $e) {
                $this->warn(
                    "  ⚠ Skipped Sub-process [{$trackType}]: " .
                        $e->getMessage(),
                );
                continue;
            } catch (\Exception $e) {
                $this->error(
                    "  ❌ Fatal Track Error [{$trackType}]: " .
                        $e->getMessage(),
                );
                return self::FAILURE;
            }
        }

        $totalJobDuration = round(microtime(true) - $totalJobStart, 2);
        Log::channel("daily")->info(
            "[BATCH UPDATE COMPLETED] Reframing processing completed in {$totalJobDuration}s",
        );

        $this->line(str_repeat("=", 80));
        $this->info(
            "✨ Batch documentation modification update pipeline completed successfully in {$totalJobDuration}s!",
        );
        return self::SUCCESS;
    }
}
