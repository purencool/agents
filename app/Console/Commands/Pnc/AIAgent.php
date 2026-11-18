<?php

declare(strict_types=1);

namespace App\Console\Commands\Pnc;

use App\PncServices\Contracts\AgentDataInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command that executes a single AI agent skill run.
 *
 * Accepts a raw JSON payload as a positional argument containing the
 * skill key, prompt, optional system override, and temperature.
 * Delegates to AgentDataInterface for execution.
 *
 * @example php artisan pnc:agent '{"skill":"communications_pr.internal_communications.skills","prompt":"Draft an announcement.","system":"You are talking to software engineers.","temperature":0.7}'
 */
class AIAgent extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = "pnc:agent {data : The raw JSON string with skill, prompt, system, temperature}";

    /**
     * The console command description.
     */
    protected $description = "Execute a single AI agent skill run.";

    /**
     * Execute the console command.
     *
     * Parses the JSON argument, validates required keys, delegates to
     * AgentDataInterface, and logs the result with timing.
     *
     * @param AgentDataInterface $agentData The agent data service.
     *
     * @return int Command::SUCCESS (0) or Command::FAILURE (1).
     */
    public function handle(AgentDataInterface $agentData): int
    {
        $totalJobStart = microtime(true);

        $config = json_decode($this->argument("data"), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Invalid JSON: " . json_last_error_msg());
            return self::FAILURE;
        }

        $skill = $config["skill"] ?? null;
        if ($skill === null) {
            $this->error("No skill specified.");
            return self::FAILURE;
        }

        $this->info("Executing skill: {$skill}");

        $result = $agentData->executeSkillRun($config);

        $response = $result["response"] ?? "";
        $this->info($response);

        $totalJobDuration = round(microtime(true) - $totalJobStart, 2);

        Log::channel("daily")->info(
            " [SKILL RUN COMPLETED] in {$totalJobDuration}s",
            ["skill" => $skill, "title" => $config["prompt"] ?? ""],
        );

        $this->line(str_repeat("=", 80));
        $this->info(" Done in {$totalJobDuration}s!");

        return self::SUCCESS;
    }
}
