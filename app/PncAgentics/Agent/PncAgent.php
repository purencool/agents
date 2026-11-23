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
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use NeuronAI\Chat\Messages\UserMessage;

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
 *  - pnc.ai.base_url       (string) API endpoint
 *  - pnc.ai.api_key        (string) API key
 *  - pnc.ai.model          (string) Model identifier
 *  - pnc.ai.timeout        (int)    Request timeout in seconds
 *  - pnc.ai.system_prompt  (string) Default system instruction
 *  - pnc.ai.context_window (int)    Token budget for chat history
 */
class PncAgent extends Agent
{
    /**
     * Embedding client for text vectorisation.
     *
     * Uses a separate model from the chat provider (e.g. text-embedding-3-small)
     * and hits the /embeddings endpoint.
     */
    protected OpenAILikeEmbeddings $embeddings;

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
    /**
     * Construct the agent and initialise the embedding client.
     */
    public function __construct(array $parameters = [])
    {
        parent::__construct();
        $this->runtimeParameters = $parameters;

        $this->embeddings = new OpenAILikeEmbeddings(
            baseUri: config("pnc.ai.base_url"),
            key: config("pnc.ai.api_key"),
            model: config("pnc.ai.embed_model"),
        );
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
            "timeout" => (int) config("pnc.ai.timeout", 30),
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
            baseUri: config("pnc.ai.base_url"),
            key: config("pnc.ai.api_key"),
            model: config("pnc.ai.model"),
            parameters: $parameters,
        );
    }

    /**
     * Configure the message store for this agent.
     *
     * Uses InMemoryMessageStore, which keeps messages only for the current
     * request cycle. This is the correct default for SkillExecutor calls
     * where each skill invocation is a self-contained, stateless prompt.
     *
     * To enable persistent multi-turn conversations across requests,
     * replace with:
     *
     *   return new FileMessageStore(
     *       directory: storage_path("neuron_chats"),
     *   );
     *
     *   return new SQLMessageStore(
     *       pdo: $pdo,
     *       table: "chat_history",
     *   );
     *
     * @return MessageStoreInterface The message store instance.
     */
    protected function messageStore(): MessageStoreInterface
    {
        return new InMemoryMessageStore();
    }

    /**
     * Return the context window (token budget) for chat history trimming.
     *
     * Neuron uses this to trim the message thread before sending to the LLM.
     * Set slightly below the model's actual limit (5–10% margin) to avoid
     * API rejections from token overruns.
     *
     * @return int Token budget.
     */
    protected function contextWindow(): int
    {
        return (int) config("pnc.ai.context_window", 50000);
    }

    /**
     * Perform a one-shot completion.
     *
     * Configures the agent with the given prompts and options,
     * sends the message, and returns the response text.
     *
     * @param string $systemPrompt System instruction.
     * @param string $userPrompt   User message.
     * @param array  $options      Runtime params (temperature, max_tokens).
     *
     * @return string The model's response text.
     */
    public function complete(
        string $systemPrompt,
        string $userPrompt,
        array $options = [],
    ): string {
        $this->runtimeParameters = $options;
        $this->setThreadId(uniqid("one-shot-", true));
        $this->setInstructions($systemPrompt);

        $response = $this->chat(new UserMessage($userPrompt));

        return $response->getMessage()->getContent() ?? "";
    }

    /**
     * Send a chat message on a specific thread.
     *
     * @param string $threadId     Conversation identifier.
     * @param string $systemPrompt System instruction.
     * @param string $userPrompt   User message.
     * @param array  $options      Runtime params (temperature, max_tokens).
     *
     * @return string The model's response text.
     */
    public function chatOnThread(
        string $threadId,
        string $systemPrompt,
        string $userPrompt,
        array $options = [],
    ): string {
        $this->runtimeParameters = $options;
        $this->setThreadId($threadId);
        $this->setInstructions($systemPrompt);

        $response = $this->chat(new UserMessage($userPrompt));

        return $response->getMessage()->getContent() ?? "";
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
            config("pnc.ai.system_prompt", "You are a helpful assistant."),
        );
    }

    /**
     * Generate a vector embedding for the given text.
     *
     * @param string $text The text to embed.
     *
     * @return float[] The embedding vector.
     */
    public function embed(string $text): array
    {
        return $this->embeddings->embedText($text);
    }
}
