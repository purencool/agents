<?php

namespace App\Console\Commands\Pnc;

use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use App\PncServices\Contracts\AgentStoreInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
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
    protected $description = "Accepts a JSON payload with domain, type, title, description, and an array of content_tracks to batch generate AI assets";

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

        // Validate both options as strict, independent, required keys
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

        $this->info("Processing JSON Data successfully structuralized:");
        $this->line(" • Domain: " . $payload["domain"]);
        $this->line(" • Type:   " . $payload["type"]);
        $this->line(" • Title:  " . $payload["title"]);
        $this->line(" • Desc:   " . $payload["description"]);
        $this->line(
            " • AI Tracks Queued: " . implode(", ", $payload["content_tracks"]),
        );
        $this->line(str_repeat("=", 80));

        // Loop orchestrations across the target content tracks array
        foreach ($payload["content_tracks"] as $trackType) {
            $this->comment(
                "🤖 Starting pipeline generation track branch: [{$trackType}]",
            );

            try {
                // Compile the payload frame, preserving both 'type' and the current 'track' context
                $trackPayload = [
                    "domain" => $payload["domain"],
                    "type" => $payload["type"], // Natively preserved for your database/REST mappings
                    "title" => $payload["title"],
                    "description" => $payload["description"],
                    "content_track" => $trackType, // Provided for tracking isolation
                ];

                // Scaffold the directory structure (e.g., config/, dev/, stage/)
                $targetDirectory = $agentStore->createStructure($trackPayload);

                // Request the universal strategy out of the Factory using the
                // specific track indicator
                $resolved = $workflowFactory->make(
                    $trackType,
                    $isUpdate = false,
                );
                $workflow = $resolved["workflow"];
                $baseSkillKey = $resolved["base_key"];

                $this->info(
                    "  -> Running multi-agent prompt loops via: {$baseSkillKey}",
                );

                // Run the resolved strategy pipeline to generate the
                // markdown document content
                $markdownResult = $workflow->execute(
                    $baseSkillKey,
                    $trackPayload,
                    $targetDirectory,
                );

                // Persist the AI generation text payloads down into your
                // target files
                file_put_contents(
                    "{$targetDirectory}/dev/master.md",
                    $markdownResult,
                );
                file_put_contents(
                    "{$targetDirectory}/stage/master.md",
                    $markdownResult,
                );

                $this->info(
                    "  ✔ Success: Content track [{$trackType}] created at: {$targetDirectory}",
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
            "✨ Batch documentation creation workflow pipeline completed successfully!",
        );
        return self::SUCCESS;
    }
}
