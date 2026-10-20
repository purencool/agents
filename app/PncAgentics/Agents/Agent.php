<?php

namespace App\PncAgentics\Agents;

/**
 *
 *
 */
class Agent extends BaseAgent
{
    /**
     * @inherit
     */
    public function name(): string
    {
        return "Agent";
    }

    /**
     * @inherit
     */
    public function run(array $input): array
    {
        $response = $this->think(
            $input["prompt"] ?? "",
            $input["system"] ?? "",
            $input["temperature"] ?? "",
        );

        return ["agent" => $this->name(), "response" => $response];
    }
}
