<?php

namespace App\Console\Commands\Pnc;

use App\PncServices\Agent\Data\AgentPayloadData;
use App\PncTemplates\Contracts\TemplateTransformerInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DataUpdate extends Command
{
    protected $signature = "pnc:agent:data:update {data : The raw JSON string object}";

    protected $description = "Accepts a JSON payload to batch update existing content track files";

    public function __construct(
        protected TemplateTransformerInterface $transformer,
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

        $this->info("Initiating content update pipeline:");
        $this->line(" • Domain: " . $payloadData->domain);
        $this->line(" • Title:  " . $payloadData->title);
        $this->line(str_repeat("=", 80));

        foreach (
            $payloadData->contentTracks
            as $trackName => $templateFilename
        ) {
            $this->comment(
                "Processing update for track [{$trackName}] via template [{$templateFilename}]",
            );

            try {
                $result = $this->transformer->transform(
                    $templateFilename,
                    $payloadData->toArray(),
                    $payloadData->toArray(),
                );

                $this->info("  ✓ Updated: {$result["target_directory"]}");
            } catch (InvalidArgumentException $e) {
                $this->warn("  ⚠ Skipped [{$trackName}]: " . $e->getMessage());
                continue;
            } catch (\Exception $e) {
                $this->error("  ❌ Fatal [{$trackName}]: " . $e->getMessage());
                return self::FAILURE;
            }
        }

        $duration = round(microtime(true) - $totalJobStart, 2);
        Log::channel("daily")->info(
            "[BATCH UPDATE COMPLETED] Update pipeline finished in {$duration}s",
        );

        $this->line(str_repeat("=", 80));
        $this->info("Batch update pipeline completed in {$duration}s");
        return self::SUCCESS;
    }
}
