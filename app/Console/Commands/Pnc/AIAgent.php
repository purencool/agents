<?php

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;
use App\PncServices\Contracts\LoggingServiceInterface;
use App\PncAgentics\Agents\Agent as Agent;

/**
 *
 */
class AIAgent extends Command
{
    
    /**
     *
     */
    protected $signature = 'pnc:agent
                            {--input= : JSON string with prompt, system, temperature, etc.}';

    /**
     *
     */
    protected $description = 'Create documentation.';

    /**
     *
     */
    public function handle(
        LoggingServiceInterface $logger,
        Agent $agent
    ): int {
        if ($this->option('input')) {
            $raw = $this->option('input');
        } else {
            $this->error('{"prompt":"No prompt was offered."}');
            return self::FAILURE;
        }

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON: ' . json_last_error_msg());
            return self::FAILURE;
        }

        $skillsRequest = $data['skill'] ?? null;

        if ($skillsRequest === null) {
            $this->error('No skill specified.');
            return self::FAILURE;
        }

        $skills = config('pnc.skills');
        $system = $data['system'] ?? '';
        $skill  = $skills[$skillsRequest] ?? null;

        if ($skill === null) {
            $this->error("Skill '{$skillsRequest}' did not load.");
            return self::FAILURE;
        }

        $input = [
            'prompt'      => $data['prompt'] ?? '',
            'system'      => $skill . $system,
            'temperature' => $data['temperature'] ?? 0.3,
        ];

        $result = $agent->run($input);

        $this->info($result['response']);

        return self::SUCCESS;
    }
}
