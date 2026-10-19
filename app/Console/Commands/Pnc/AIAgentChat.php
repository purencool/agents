<?php

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;
use App\PncServices\Contracts\LoggingServiceInterface;
use App\PncAgentics\Agents\Agent;

/**
 * @example  php artisan pnc:agent:chat --input='{
 *   "prompt": "How do I implement dependency injection in Laravel?",
 *   "system": "You are a senior PHP developer answering technical questions.",
 *   "temperature": 0.5
 * }'
 *
 */
class AIAgentChat extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = "pnc:agent:chat {--input= : JSON string containing the question/prompt}";

    /**
     * The console command description.
     */
    protected $description = "Answer questions via the AI Chat Agent using a single JSON request.";

    /**
     * Execute the console command.
     */
    public function handle(LoggingServiceInterface $logger, Agent $agent): int
    {
        $raw = $this->option("input");

        if (!$raw) {
            $this->error(
                '{"error": "No input was provided. Please supply a JSON payload."}',
            );
            return self::FAILURE;
        }

        // Attempt to decode as JSON first
        $data = json_decode($raw, true);

        // If it's not valid JSON, it means the user typed a plain conversational message string!
        // We catch it and automatically assign it to the 'prompt' key to keep everything moving cleanly.
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $data = [
                "prompt" => $raw,
            ];
        }

        // Keep your strict parameter verification logic intact
        if (empty($data["prompt"])) {
            $this->error(
                '{"error": "The request must contain a \'prompt\' value with your question."}',
            );
            return self::FAILURE;
        }

        // Prepare the payload for the Agent, defaulting the system prompt for general Q&A
        $input = [
            "prompt" => $data["prompt"],
            "system" =>
                $data["system"] ??
                "You are a helpful AI assistant that answers questions accurately and concisely.",
            "temperature" => $data["temperature"] ?? 0.7,
        ];

        try {
            $result = $agent->run($input);

            // Output the direct response from the agent
            $this->info(
                $result["response"] ??
                    '{"error": "No response returned from the agent."}',
            );

            return self::SUCCESS;
        } catch (\Exception $e) {
            $logger->error(
                "AIAgentChat encountered an error: " . $e->getMessage(),
            );
            $this->error('{"error": "Agent execution failed."}');

            return self::FAILURE;
        }
    }
}
