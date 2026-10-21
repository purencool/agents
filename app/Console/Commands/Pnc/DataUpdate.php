<?php

namespace App\Console\Commands\Pnc;

use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use App\PncServices\Contracts\AgentStoreInterface;
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
    protected $description = "Accepts a JSON payload to batch reframe, rewrite, and update existing target content track files using AI";

    /**
     * Execute the console command.
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

        // Enforce strict, distinct keys
        // matching your architectural parameters
        $validator = Validator::make($payload, [
            "domain" => "required|string",
            "type" => "required|string",
            "title" => "required|string",
            "description" => "required|string",
            "content_tracks" => "required|array|min:1",
            "content_tracks.*" => "required|string",
        ]);

        if ($validator->fails()) {
            $this->error(
                "Validation Failed: The JSON payload contains missing or invalid keys.",
            );
            foreach ($validator->errors()->all() as $error) {
                $this->warn("- $error");
            }
            return self::FAILURE;
        }

        $this->info("Initiating AI Content Reframing Batch Workflow Pipeline:");
        $this->line(" • Domain: " . $payload["domain"]);
        $this->line(" • Type:   " . $payload["type"]);
        $this->line(" • Title:  " . $payload["title"]);
        $this->line(" • New Instructions: " . $payload["description"]);
        $this->line(
            " • Tracks queued for modification updates: " .
                implode(", ", $payload["content_tracks"]),
        );
        $this->line(str_repeat("=", 80));

        $domainSlug = Str::slug($payload["domain"], "_");
        $typeSlug = Str::slug($payload["type"], "_");
        $titleSlug = Str::slug($payload["title"], "_");

        $baseDataStore = base_path("../data/agent/data");
        $typeDirectory = "{$baseDataStore}/{$domainSlug}/{$typeSlug}";

        if (!is_dir($typeDirectory)) {
            $this->error(
                "Cannot update: The core domain/type directory structure does not exist at [{$typeDirectory}].",
            );
            return self::FAILURE;
        }

        // 2. Loop orchestrations across active targets inside the content_tracks array
        foreach ($payload["content_tracks"] as $trackType) {
            $this->comment(
                "🤖 Processing refinement updates for track branch: [{$trackType}]",
            );

            try {
                // Compile isolated payload details for historical path
                // generation matches
                $trackPayload = [
                    "domain" => $payload["domain"],
                    "type" => $payload["type"],
                    "title" => $payload["title"],
                    "description" => $payload["description"],
                    "content_track" => $trackType,
                ];

                // Scan historical date-bound tracking trees to
                // locate the specific target directory matching the title
                $targetDirectory = null;
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
                    $this->warn(
                        "  ⚠ Skipped Sub-process [{$trackType}]: No installed content instance found matching the title '{$payload["title"]}'.",
                    );
                    continue;
                }

                // Extract historical content canvas from file systems
                // to provide context to refinement agents
                $masterPath = "{$targetDirectory}/master.md";
                $currentContent = file_exists($masterPath)
                    ? file_get_contents($masterPath)
                    : "";

                // Query the factory layer to load your central
                // UpdateRefiningWorkflow strategy instance
                $resolved = $workflowFactory->make(
                    $trackType,
                    $isUpdate = true,
                );
                $workflow = $resolved["workflow"];
                $baseSkillKey = $resolved["base_key"];

                $this->info(
                    "  -> Injecting history context into refinery track via: {$baseSkillKey}",
                );

                // Run the resolved strategy script execution sequence
                // cleanly matching the update signature
                $markdownResult = $workflow->execute(
                    $baseSkillKey,
                    $trackPayload,
                    $targetDirectory,
                    $currentContent,
                );

                // Commit reframed output logs straight back to disk nodes safely
                file_put_contents($masterPath, $markdownResult);

                // Update tracking metrics metadata details payload mapping trace
                $trackPayload["updated_at"] = now()->toIso8601String();
                file_put_contents(
                    "{$targetDirectory}/payload.json",
                    json_encode(
                        $trackPayload,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    ),
                );

                $this->info(
                    "  ✔ Success: Reframed content file saved cleanly at: {$masterPath}",
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

        $this->line(str_repeat("=", 80));
        $this->info(
            "✨ Batch documentation modification update pipeline completed successfully!",
        );
        return self::SUCCESS;
    }
}
