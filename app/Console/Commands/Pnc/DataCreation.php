<?php

namespace App\Console\Commands\Pnc;

use App\PncServices\Contracts\AgentDataInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;

class DataCreation extends Command
{
    protected $signature = "pnc:agent:data:creation {data : The raw JSON string object}";
    protected $description = "Accepts a JSON payload to create structure and batch generate AI assets";

    public function handle(AgentDataInterface $agentData): int
    {
        $totalJobStart = microtime(true);

        $config = json_decode($this->argument("data"), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Invalid JSON: " . json_last_error_msg());
            return self::FAILURE;
        }

        $this->info("Creating structure: {$config["title"]}");

        // Use the method variable injection directly
        $structure = $agentData->executeFullCreationPipeline($config);

        // Dig into the 'structure' key returned by your service pipeline
        $pipelineStructure = $structure["structure"] ?? [];

        $uuid = $pipelineStructure["uuid"] ?? "N/A";
        $tracksCount = isset($pipelineStructure["tracks"])
            ? count($pipelineStructure["tracks"])
            : 0;

        $this->line("UUID: {$uuid}  Tracks: {$tracksCount}");

        $totalJobDuration = round(microtime(true) - $totalJobStart, 2);

        $pipelineStructure = $structure["structure"] ?? $structure;
        $resolvedUuid = $pipelineStructure["uuid"] ?? "N/A";
        Log::channel("daily")->info(
            " [BATCH CREATION COMPLETED] in {$totalJobDuration}s",
            ["uuid" => $resolvedUuid, "title" => $config["title"]],
        );

        $this->line(str_repeat("=", 80));
        $this->info(" Done in {$totalJobDuration}s!");
        return self::SUCCESS;
    }
}
