<?php

namespace App\PncServices\AI;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;

class PncAgent extends Agent
{
    protected array $runtimeParameters = [];

    public function __construct(array $parameters = [])
    {
        parent::__construct();
        $this->runtimeParameters = $parameters;
    }

    /**
     * Fixes the declared return type incompatibility.
     */
    protected function provider(): AIProviderInterface
    {
        $parameters = [
            "timeout" => (int) config("pncservices.ai.timeout", 30),
        ];

        if (isset($this->runtimeParameters["temperature"])) {
            $parameters["temperature"] =
                (float) $this->runtimeParameters["temperature"];
        }
        if (isset($this->runtimeParameters["max_tokens"])) {
            $parameters["max_tokens"] =
                (int) $this->runtimeParameters["max_tokens"];
        }

        return new OpenAILike(
            baseUri: config("pncservices.ai.base_url"),
            key: config("pncservices.ai.api_key"),
            model: config("pncservices.ai.model"),
            parameters: $parameters,
        );
    }

    public function instructions(): SystemMessage
    {
        return new SystemMessage(
            config(
                "pncservices.ai.system_prompt",
                "You are a helpful assistant.",
            ),
        );
    }
}
