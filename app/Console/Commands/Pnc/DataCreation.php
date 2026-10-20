<?php

namespace App\Console\Commands\Pnc;

use App\PncAgentics\Contracts\WorkflowFactoryInterface; // <-- Swapped to factory interface
use App\PncServices\Contracts\AgentStoreInterface; // <-- Injected to create folders
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class DataCreation extends Command
{
    /**
     * The name and signature of the console command.
     * We use {data} as a required argument to hold the payload.
     */
    protected $signature = "pnc:agent:data:creation {data : The raw JSON string object}";

    /**
     * The console command description.
     */
    protected $description = "Accepts a JSON payload with domain, type, title, and description fields to generate AI documentation";

    /**
     * Execute the console command.
     *
     * Injects the unified workflow factory and the system storage manager service layer.
     */
    public function handle(
        WorkflowFactoryInterface $workflowFactory,
        AgentStoreInterface $agentStore,
    ): int {
        // Retrieve the raw argument string
        $rawData = $this->argument("data");

        // Attempt to parse the payload as JSON
        $payload = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error(
                "Invalid input: The provided string is not valid JSON.",
            );
            $this->error("JSON Error: " . json_last_error_msg());
            return self::FAILURE;
        }

        // Validate the object contains the exact keys needed
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

        // Process the validated payload data safely
        $this->info("Processing JSON Data successfully structuralized:");

        $listItems = [
            "Domain: " . $payload["domain"],
            "Type:   " . $payload["type"],
            "Title:  " . $payload["title"],
            "Desc:   " . $payload["description"],
        ];

        // Display the elements using a simple console line loop
        foreach ($listItems as $item) {
            $this->line(" • {$item}");
        }

        try {
            // 1. Scaffold the filesystem folder structural trees (e.g., config/, dev/, stage/)
            // This method checks if the folder exists, preventing accidental overrides automatically.
            $targetDirectory = $agentStore->createStructure($payload);

            // 2. Query the Factory to resolve the target processing creation strategy engine
            // Based dynamically on whether 'type' matches 'ai_agents', 'developer_docs', etc.
            $workflow = $workflowFactory->make(
                $payload["type"],
                $isUpdate = false,
            );

            $this->comment(
                "Executing dynamic multi-agent prompt workflow pipeline...",
            );

            // 3. Run the resolved strategy pipeline to generate the markdown documentation canvas content
            $markdownResult = $workflow->execute($payload, $targetDirectory);

            // 4. Persist the AI generation text payloads down into your target files
            file_put_contents(
                "{$targetDirectory}/dev/master.md",
                $markdownResult,
            );
            file_put_contents(
                "{$targetDirectory}/stage/master.md",
                $markdownResult,
            );

            $this->info(
                "Success: Multi-agent documentation ecosystem created cleanly!",
            );
            $this->line(" • Core Node: {$targetDirectory}");
            return self::SUCCESS;
        } catch (InvalidArgumentException $e) {
            // Catches the custom folder duplicate check safely from the lower storage service layer
            $this->warn("Skipped Process: " . $e->getMessage());
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Fatal Workflow Error: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
