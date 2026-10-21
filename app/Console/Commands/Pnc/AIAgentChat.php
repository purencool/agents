<?php

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;
use App\PncServices\Contracts\LoggingServiceInterface;
use App\PncAgentics\Agents\Agent;
use App\PncAgentics\Contracts\SkillExecutorInterface; // <-- Injected to resolve custom skill lookups
use Illuminate\Support\Str;

/**
 * @example  php artisan pnc:agent:chat --input='{
 *   "prompt": "How do I implement dependency injection in Laravel?",
 *   "system": "You are a senior PHP developer answering technical questions.",
 *   "temperature": 0.5
 * }'
 *
 * @example  php artisan pnc:agent:chat --input="Explain interfaces" --skill="developer_docs.core_writing.hook_introduction_writer.skills"
 */
class AIAgentChat extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = "pnc:agent:chat
                            {--input= : JSON string containing the question/prompt or raw conversational text}
                            {--skill= : Dynamic dot-notation skill key to use as the system prompt instructions}";

    /**
     * The console command description.
     */
    protected $description = "Answer questions via the AI Chat Agent using a single JSON request, optionally driven by enterprise markdown skills.";

    /**
     * Execute the console command.
     */
    public function handle(
        LoggingServiceInterface $logger,
        Agent $agent,
        SkillExecutorInterface $skillExecutor,
    ): int {
        $raw = $this->option("input");
        $skillOption = $this->option("skill");

        if (!$raw) {
            $this->error(
                '{"error": "No input was provided. Please supply a JSON payload or conversational string."}',
            );
            return self::FAILURE;
        }

        // Attempt to decode input as JSON first
        $data = json_decode($raw, true);

        // Fallback: If it's a raw string, normalize it cleanly into the
        // prompt data structure
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $data = [
                "prompt" => $raw,
            ];
        }

        if (empty($data["prompt"])) {
            $this->error(
                '{"error": "The request must contain a \'prompt\' value with your question."}',
            );
            return self::FAILURE;
        }

        // Resolve system instructions either from the JSON payload
        // or the CLI flag parameter
        $systemInstructions = $data["system"] ?? null;

        if ($skillOption) {
            try {
                // Prepend your default enterprise root namespace if
                // the shortcut is passed short
                $fullSkillKey = $skillOption;
                if (!Str::startsWith($skillOption, "sales_marketing.")) {
                    $fullSkillKey = "sales_marketing.marketing.external_documentation.{$skillOption}";
                }

                // Leverage the dynamic SkillExecutor to map the path,
                // open the file, and load the prompts live
                $relativePath = str_replace(
                    ".",
                    DIRECTORY_SEPARATOR,
                    $fullSkillKey,
                );
                $filePath = app_path(
                    "PncAgentics" .
                        DIRECTORY_SEPARATOR .
                        "Skills" .
                        DIRECTORY_SEPARATOR .
                        $relativePath .
                        ".md",
                );

                if (file_exists($filePath)) {
                    $systemInstructions = file_get_contents($filePath);
                    $this->comment(
                        "🤖 Driven dynamically by skill context file: [{$fullSkillKey}]",
                    );
                } else {
                    $this->warn(
                        "⚠ Skill file not found at [{$filePath}]. Falling back to standard prompt configurations.",
                    );
                }
            } catch (\Exception $e) {
                $logger->error(
                    "AIAgentChat failed loading skill path context: " .
                        $e->getMessage(),
                );
            }
        }

        // Prepare the final execution inputs payload mapping array
        $input = [
            "prompt" => $data["prompt"],
            "system" =>
                $systemInstructions ??
                "You are a helpful AI assistant that answers questions accurately and concisely.",
            "temperature" => $data["temperature"] ?? 0.7,
        ];

        try {
            $result = $agent->run($input);

            // Output the direct response from the agent
            if (isset($result["response"])) {
                $this->info($result["response"]);
            } else {
                $this->error(
                    '{"error": "No response returned from the agent."}',
                );
            }

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
