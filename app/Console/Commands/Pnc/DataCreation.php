<?php

namespace App\Console\Commands\Pnc;

use App\PncAgentics\Contracts\DocumentationWorkflowInterface;
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
    protected $description = "Accepts a JSON payload with domain, type, title, and description fields";

    /**
     * Execute the console command.
     *
     */
    public function handle(DocumentationWorkflowInterface $docWorkflow): int
    {
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
                "Validation Failed: The JSON payload is missing
                required keys or contains invalid types.",
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
            $success = $docWorkflow->run($payload);

            if ($success) {
                $this->info(
                    "Success: Multi-agent documentation ecosystem created cleanly!",
                );
                return self::SUCCESS;
            }

            $this->error("Workflow completed with internal processing errors.");
            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->warn("Skipped: " . $e->getMessage());
        }
    }
}
