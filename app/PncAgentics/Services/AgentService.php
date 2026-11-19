<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\AgentServiceInterface;
use App\PncAgentics\Agent\PncAgent;
use App\PncServices\Contracts\LoggingServiceInterface;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;

/**
 * Agent service that provides completion, embedding, and chat capabilities.
 *
 * Wraps NeuronAI's agent and embedding clients behind a stable interface,
 * adding structured error logging on failure.
 */
class AgentService implements AgentServiceInterface
{
    /**
     * Embedding client for text vectorisation.
     */
    protected OpenAILikeEmbeddings $embeddings;

    /**
     * @param LoggingServiceInterface $logger Application-wide structured logger.
     */
    public function __construct(protected LoggingServiceInterface $logger)
    {
        $this->embeddings = new OpenAILikeEmbeddings(
            baseUri: config("pncservices.ai.base_url"),
            key: config("pncservices.ai.api_key"),
            model: config("pncservices.ai.model"),
        );
    }

    /**
     * Create a PncAgent instance.
     *
     * Overridable for testing.
     *
     * @param array $options Agent configuration parameters.
     */
    protected function createAgent(array $options = []): PncAgent
    {
        return new PncAgent($options);
    }

    /**
     * Generate a one-shot completion from the AI model.
     *
     * @param string $prompt  The user prompt.
     * @param array  $options Optional: "system" (string), "temperature", "max_tokens".
     *
     * @return string The model's text response.
     *
     * @throws \Throwable Re-thrown after logging.
     */
    public function complete(string $prompt, array $options = []): string
    {
        $agent = $this->createAgent($options);
        $agent->setThreadId("one-shot");

        if (!empty($options["system"])) {
            $agent->setInstructions((string) $options["system"]);
        }

        try {
            $content = $agent
                ->chat(new UserMessage($prompt))
                ->getMessage()
                ->getContent();

            return $content ?? "";
        } catch (\Throwable $e) {
            Log::error("AIService::complete — request failed", [
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate a vector embedding for the given text.
     *
     * @param string $text The text to embed.
     *
     * @return float[] The embedding vector.
     *
     * @throws \Throwable Re-thrown after logging.
     */
    public function embed(string $text): array
    {
        try {
            return $this->embeddings->embedText($text);
        } catch (\Throwable $e) {
            Log::error("AIService::embed — request failed", [
                "error" => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Send a chat message and return the model's response.
     *
     * @param string $message The user message.
     * @param array  $history Conversation history (reserved).
     *
     * @return string The model's text response.
     *
     * @throws \Throwable Re-thrown after logging.
     */
    public function chat(string $message, array $history = []): string
    {
        $threadId = "default";

        try {
            $agent = $this->createAgent();
            $agent->setThreadId($threadId);

            $content = $agent
                ->chat(new UserMessage($message))
                ->getMessage()
                ->getContent();

            return $content ?? "";
        } catch (\Throwable $e) {
            Log::error("AIService::chat — request failed", [
                "thread_id" => $threadId,
                "error" => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
