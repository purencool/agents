<?php

namespace App\PncAgentics\Agents;

/**
 * @example php artisan pnc:agent --input='{"prompt":"Write documentation about the best app in the world", "skill":"documentation" }' -vvv
 * 
 */
class Agent extends BaseAgent
{
    
    /**
     * @inherit 
     */
    public function name(): string
    {
        return 'Agent';
    }

    /**
     * @inherit 
     */
    public function run(array $input): array
    {
       
        $response = $this->think(
            $input['prompt'] ?? '',
            $input['system'] ?? ''
        );

        return ['agent' => $this->name(), 'response' => $response];
    }
}