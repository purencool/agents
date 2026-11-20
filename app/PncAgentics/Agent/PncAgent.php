<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;

/**
 * PNC-specific AI agent that configures an OpenAI-compatible provider.
 *
 * Extends NeuronAI's base Agent, injecting project-specific provider
 * settings (base URL, API key, model, timeout) from config and
 * per-request runtime parameters (temperature, max_tokens).
 *
 * Chat history:
 *  Uses InMemoryChatHistory by default, which keeps messages only for
 *  the duration of a single request cycle. This is correct for the
 *  SkillExecutor use case where each skill call is stateless.
 *
 *  If you need conversations to persist across requests (e.g., a user
 *  chatting over multiple page loads), swap the chatHistory() return
 *  value for FileChatHistory or EloquentChatHistory.
 *
 * Configuration keys (config/pncservices.php):
 *  - pncservices.ai.base_url       (string) API endpoint
 *  - pncservices.ai.api_key        (string) API key
 *  - pncservices.ai.model          (string) Model identifier
 *  - pncservices.ai.timeout        (int)    Request timeout in seconds
 *  - pncservices.ai.system_prompt  (string) Default system instruction
 *  - pncservices.ai.context_window (int)    Token budget for chat history
 */
class PncAgent extends Agent
{
    /**
     * Per-request runtime parameters passed via the constructor.
     *
     * Supported keys:
     *  - "temperature" (float): Sampling temperature (0.0 – 2.0)
     *  - "max_tokens"  (int):   Maximum tokens in the response
     */
    protected array $runtimeParameters = [];

    /**
     * @param array $parameters Runtime parameters for this agent instance.
     */
    public function __construct(array $parameters = [])
    {
        parent::__construct();
        $this->runtimeParameters = $parameters;
    }

    /**
     * Build and return the AI provider for this agent.
     *
     * Reads base configuration from config/pncservices.php and merges
     * in any per-request overrides from $this->runtimeParameters.
     *
     * @return AIProviderInterface The configured OpenAILike provider.
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

    /**
     * Configure the chat history store for this agent.
     *
     * Uses InMemoryChatHistory, which keeps messages only for the current
     * request cycle. This is the correct default for SkillExecutor calls
     * where each skill invocation is a self-contained, stateless prompt.
     *
     * The contextWindow is set slightly below the model's actual limit
     * (5–10% margin) to avoid API rejections from token overruns.
     *
     * To enable persistent multi-turn conversations across requests,
     * replace with:
     *
     *   return new FileChatHistory(
     *       directory: storage_path("neuron_chats"),
     *       key: $this->threadId,
     *       contextWindow: (int) config("pncservices.ai.context_window", 50_000),
     *   );
     *
     *   return new EloquentChatHistory(
     *       threadId: $this->threadId,
     *       modelClass: ChatMessage::class,
     *       contextWindow: (int) config("pncservices.ai.context_window", 50_000),
     *   );
     *
     * @return ChatHistoryInterface The chat history store instance.
     */
    protected function chatHistory(): ChatHistoryInterface
    {
        return new InMemoryChatHistory(
            contextWindow: (int) config("pncservices.ai.context_window", 50000),
        );
    }

    /**
     * Return the system instruction message for this agent.
     *
     * Reads the prompt from config, falling back to a generic
     * helpful-assistant instruction if not configured.
     *
     * @return SystemMessage The system message to prepend to conversations.
     */
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
