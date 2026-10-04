<?php

namespace App\PncAgentics\Agents;

class ExampleAgent extends BaseAgent
{
    public function name(): string
    {
        return 'ExampleAgent';
    }

    public function run(array $input): array
    {
        $response = $this->think($input['prompt'] ?? 'Hello');
        return ['agent' => $this->name(), 'response' => $response];
    }
}
