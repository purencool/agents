<?php

namespace App\Console\Commands\Pnc;

use App\PncAgentics\Contracts\WorkflowFactoryInterface; // <-- Updated factory import
use App\PncServices\Contracts\AgentStoreInterface; // <-- Added to find historical folders safely
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DataUpdate extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = "pnc:agent:data:update {data : The raw JSON string object}";

    /**
     * The console command description.
     */
    protected $description = "Accepts a JSON payload to reframe, rewrite, and update existing documentation files using AI";

    /**
     * Execute the console command.
     *
     * Injects the Factory Interface and Agent Store dependency layers.
     */
    public function handle(
        WorkflowFactoryInterface $workflowFactory,
        AgentStoreInterface $agentStore,
    ): int {
        $rawData = $this->argument("data");
        $payload = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error(
                "Invalid input: The provided string is not valid JSON.",
            );
            $this->error("JSON Error: " . json_last_error_msg());
            return self::FAILURE;
        }

        $validator = Validator::make($payload, [
            "domain" => "required|string",
            "type" => "required|string",
            "title" => "required|string",
            "description" => "required|string",
        ]);

        if ($validator->fails()) {
            $this->error(
                "Validation Failed: The JSON payload is missing required keys or contains invalid types.",
            );
            foreach ($validator->errors()->all() as $error) {
                $this->warn("- $error");
            }
            return self::FAILURE;
        }

        $this->info("Initiating AI Content Reframing Workflow Pipeline:");
        $listItems = [
            "Domain: " . $payload["domain"],
            "Type:   " . $payload["type"],
            "Title:  " . $payload["title"],
            "New Description: " . $payload["description"],
        ];

        foreach ($listItems as $item) {
            $this->line(" • {$item}");
        }

        try {
            // 1. Resolve and calculate the location of the installed target directory path structure
            $domain = Str::slug($payload["domain"], "_");
            $type = Str::slug($payload["type"], "_");
            $titleSlug = Str::slug($payload["title"], "_");

            $baseDataStore = base_path("../data/agent/data");
            $typeDirectory = "{$baseDataStore}/{$domain}/{$type}";
            $targetDirectory = null;

            if (!is_dir($typeDirectory)) {
                throw new InvalidArgumentException(
                    "Cannot update: The domain/type directory structure does not exist.",
                );
            }

            // Scan historical date-bound tracking trees to find the existing directory matching the title
            $dateDirectories = array_filter(
                glob("{$typeDirectory}/*"),
                "is_dir",
            );
            foreach ($dateDirectories as $dateDir) {
                if (is_dir("{$dateDir}/{$titleSlug}")) {
                    $targetDirectory = "{$dateDir}/{$titleSlug}";
                    break;
                }
            }

            if (!$targetDirectory) {
                throw new InvalidArgumentException(
                    "Cannot update: No installed instance found matching the title '{$payload["title"]}'.",
                );
            }

            // Extract existing content canvas history to provide context mapping to the refinery agents
            $masterPath = "{$targetDirectory}/master.md";
            $currentContent = file_exists($masterPath)
                ? file_get_contents($masterPath)
                : "";

            // Resolve the update-specific modular strategy through the Factory
            $workflow = $workflowFactory->make(
                $payload["type"],
                $isUpdate = true,
            );

            // Run the isolated strategy pipeline step logic loops
            $this->comment("Executing refinement processing pipelines...");
            $markdownResult = $workflow->execute(
                $payload,
                $targetDirectory,
                $currentContent,
            );

            // Safely persist updates to target files
            file_put_contents($masterPath, $markdownResult);

            // Update the structural system metadata tracking footprint array
            $payload["updated_at"] = now()->toIso8601String();
            file_put_contents(
                "{$targetDirectory}/payload.json",
                json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ),
            );

            $this->info(
                "Success: Multi-agent documentation ecosystem updated and reframed cleanly!",
            );
            $this->line(" • Updated Node: {$targetDirectory}/master.md");
            return self::SUCCESS;
        } catch (InvalidArgumentException $e) {
            $this->error("Skipped Process: " . $e->getMessage());
            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error("Fatal Error: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
