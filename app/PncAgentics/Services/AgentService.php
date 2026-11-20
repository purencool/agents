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
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;

/**
 * Agent service that provides completion, embedding, and chat capabilities.
 *
 * All public methods accept a single structured array, making the LLM
 * request contract explicit, loggable, and extensible without signature
 * changes.
 *
 * Request array keys:
 *
 * | Key             | Type    | Required | Description                                      |
 * |-----------------|---------|----------|--------------------------------------------------|
 * | system_prompt   | string  | Yes      | System instruction (role, protocol, rules).      |
 * | user_prompt     | string  | Yes      | The data/content the model operates on.          |
 * | temperature     | float   | No       | Sampling temperature (0.0 – 2.0).                |
 * | max_tokens      | int     | No       | Maximum response tokens.                         |
 * | thread_id       | string  | No       | Conversation ID for multi-turn chat.             |
 * | metadata        | array   | No       | Arbitrary context for logging/tracing.           |
 *
 * Method-specific notes:
 *  - complete(): thread_id is ignored (always "one-shot" internally).
 *  - chat():     thread_id determines conversation continuity.
 *  - embed():    accepts "text" key instead of system/user prompt;
 *               other keys are ignored.
 *
 * Multi-turn conversations:
 *  Conversation continuity is handled entirely by NeuronAI's chat history
 *  store via the thread ID. The caller passes a stable thread_id for each
 *  conversation; NeuronAI loads prior messages, trims them to fit the
 *  context window, and persists the new turn automatically.
 *
 * Debug mode (AI_ENABLE=false):
 *  - Validation still runs (required keys must be present).
 *  - complete() and chat() return a formatted string echoing the
 *    system_prompt and user_prompt that would have been sent to the LLM.
 *  - embed() returns an empty array.
 *  - A debug-level log entry is written for each suppressed call.
 *
 * This allows the full upstream pipeline (prompt building, skill resolution,
 * template compilation) to be exercised without incurring API costs while
 * still verifying that the correct prompts are being constructed.
 */
class AgentService implements AgentServiceInterface
{
    /**
     * Embedding client for text vectorisation.
     */
    protected OpenAILikeEmbeddings $embeddings;

    /**
     * Construct the service and initialise the embedding client.
     *
     * Configuration is read from config/pncservices.php:
     *  - pncservices.ai.base_url   API endpoint
     *  - pncservices.ai.api_key    API key
     *  - pncservices.ai.model      Model identifier
     */
    public function __construct()
    {
        $this->embeddings = new OpenAILikeEmbeddings(
            baseUri: config("pncservices.ai.base_url"),
            key: config("pncservices.ai.api_key"),
            model: config("pncservices.ai.model"),
        );
    }

    /**
     * Determine whether the AI service is in debug (no-op) mode.
     *
     * When AI_ENABLE is set to a falsy value ("false", "0", "no", "off")
     * in the environment, all LLM calls are suppressed. A debug log entry
     * is written identifying which method triggered the guard.
     *
     * @return bool True when LLM calls should be skipped.
     */
    protected function isDisabled(): bool
    {
        if (filter_var(env("AI_ENABLE", true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $caller =
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]["function"] ??
            "unknown";

        Log::debug("AgentService disabled — LLM call suppressed", [
            "method" => $caller,
        ]);

        return true;
    }

    /**
     * Create a PncAgent instance.
     *
     * Overridable for testing. The agent's chat history store is configured
     * on PncAgent itself (see PncAgent::chatHistory()), so this method
     * only passes runtime parameters (temperature, max_tokens).
     *
     * @param array $options Agent configuration parameters (temperature, max_tokens, etc.).
     *
     * @return PncAgent A new agent instance configured with the given options.
     */
    protected function createAgent(array $options = []): PncAgent
    {
        return new PncAgent($options);
    }

    /**
     * Extract and validate the required prompt keys from the request array.
     *
     * This runs BEFORE the debug guard so that the contract is enforced
     * even when the LLM is disabled — ensuring callers always supply
     * both a system prompt and a user prompt regardless of mode.
     *
     * @param array  $request The structured LLM request array.
     * @param string $method  The calling method name (for error messages).
     *
     * @return array{system_prompt: string, user_prompt: string}
     *
     * @throws InvalidArgumentException When either required key is missing.
     */
    protected function extractPrompts(array $request, string $method): array
    {
        $systemPrompt =
            $request["system_prompt"] ??
            throw new InvalidArgumentException(
                "AgentService::{$method} — request must include a 'system_prompt' key.",
            );

        $userPrompt =
            $request["user_prompt"] ??
            throw new InvalidArgumentException(
                "AgentService::{$method} — request must include a 'user_prompt' key.",
            );

        return [
            "system_prompt" => $systemPrompt,
            "user_prompt" => $userPrompt,
        ];
    }

    /**
     * Build a debug response that echoes the prompts that would have been
     * sent to the LLM.
     *
     * Useful for verifying prompt-building logic (skill resolution,
     * placeholder compilation, data injection) without incurring API costs.
     *
     * @param string $systemPrompt The system prompt that would be sent.
     * @param string $userPrompt   The user prompt that would be sent.
     * @param string $threadId     The thread ID (for chat context).
     *
     * @return string Formatted debug output.
     */
    protected function buildDebugResponse(
        string $systemPrompt,
        string $userPrompt,
        string $threadId = "one-shot",
    ): string {
        return sprintf(
            "You are in debug mode. A request was made to the AI service.\n\n" .
                "--- THREAD ID ---\n%s\n\n" .
                "--- SYSTEM PROMPT ---\n%s\n\n" .
                "--- USER PROMPT ---\n%s",
            $threadId,
            $systemPrompt,
            $userPrompt,
        );
    }

    /**
     * Extract optional LLM parameters from the request array.
     *
     * Returns only the keys that are present and non-null, ready to be
     * passed as constructor parameters to PncAgent.
     *
     * @param array $request The structured LLM request array.
     *
     * @return array{temperature?: float, max_tokens?: int}
     */
    protected function extractOptions(array $request): array
    {
        return array_filter(
            [
                "temperature" => $request["temperature"] ?? null,
                "max_tokens" => $request["max_tokens"] ?? null,
            ],
            fn($v) => $v !== null,
        );
    }

    /**
     * Generate a one-shot completion from the AI model.
     *
     * Stateless single-turn call. A fixed thread ID ("one-shot") is used
     * internally so no prior conversation context is loaded.
     *
     * In debug mode (AI_ENABLE=false), returns a formatted string echoing
     * the system and user prompts without contacting the LLM.
     *
     * @param array $request Structured LLM request.
     *
     *   Required keys:
     *     - "system_prompt" (string): System instruction (role, protocol, rules).
     *     - "user_prompt"   (string): The data/content to process.
     *
     *   Optional keys:
     *     - "temperature" (float): Sampling temperature (0.0 – 2.0).
     *     - "max_tokens"  (int):   Maximum response tokens.
     *     - "metadata"    (array): Arbitrary context for logging.
     *
     * @return string The model's text response, or a debug echo in debug mode.
     *
     * @throws InvalidArgumentException When "system_prompt" or "user_prompt" is missing.
     * @throws \Throwable               Re-thrown after logging on LLM failure.
     */
    public function complete(array $request): string
    {
        $prompts = $this->extractPrompts($request, "complete");

        if ($this->isDisabled()) {
            return $this->buildDebugResponse(
                $prompts["system_prompt"],
                $prompts["user_prompt"],
                "one-shot",
            );
        }

        $agent = $this->createAgent($this->extractOptions($request));
        $agent->setThreadId("one-shot");
        $agent->setInstructions($prompts["system_prompt"]);

        try {
            $content = $agent
                ->chat(new UserMessage($prompts["user_prompt"]))
                ->getMessage()
                ->getContent();

            return $content ?? "";
        } catch (\Throwable $e) {
            Log::error("AgentService::complete — request failed", [
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
                "metadata" => $request["metadata"] ?? [],
            ]);
            throw $e;
        }
    }

    /**
     * Send a chat message and return the model's response.
     *
     * Multi-turn conversation is handled by NeuronAI's chat history store:
     * the agent loads prior messages for the given thread_id, trims them
     * to fit the context window, sends the full context to the LLM, and
     * persists the new user + assistant messages back to the store.
     *
     * In debug mode (AI_ENABLE=false), returns a formatted string echoing
     * the system and user prompts without contacting the LLM.
     *
     * @param array $request Structured LLM request.
     *
     *   Required keys:
     *     - "system_prompt" (string): System instruction (role, protocol, rules).
     *     - "user_prompt"   (string): The user message to send.
     *
     *   Optional keys:
     *     - "thread_id"   (string): Conversation identifier. Defaults to "default".
     *     - "temperature" (float):  Sampling temperature (0.0 – 2.0).
     *     - "max_tokens"  (int):    Maximum response tokens.
     *     - "metadata"    (array):  Arbitrary context for logging.
     *
     * @return string The model's text response, or a debug echo in debug mode.
     *
     * @throws InvalidArgumentException When "system_prompt" or "user_prompt" is missing.
     * @throws \Throwable               Re-thrown after logging on LLM failure.
     */
    public function chat(array $request): string
    {
        $prompts = $this->extractPrompts($request, "chat");
        $threadId = $request["thread_id"] ?? "default";

        if ($this->isDisabled()) {
            return $this->buildDebugResponse(
                $prompts["system_prompt"],
                $prompts["user_prompt"],
                $threadId,
            );
        }

        $agent = $this->createAgent($this->extractOptions($request));
        $agent->setThreadId($threadId);
        $agent->setInstructions($prompts["system_prompt"]);

        try {
            $content = $agent
                ->chat(new UserMessage($prompts["user_prompt"]))
                ->getMessage()
                ->getContent();

            return $content ?? "";
        } catch (\Throwable $e) {
            Log::error("AgentService::chat — request failed", [
                "thread_id" => $threadId,
                "error" => $e->getMessage(),
                "metadata" => $request["metadata"] ?? [],
            ]);
            throw $e;
        }
    }

    /**
     * Generate a vector embedding for the given text.
     *
     * Uses the configured OpenAI-compatible embeddings endpoint.
     * No system prompt applies to embedding calls.
     *
     * In debug mode (AI_ENABLE=false), returns an empty array without
     * contacting the embedding API.
     *
     * @param array $request Structured embedding request.
     *
     *   Required keys:
     *     - "text" (string): The text to embed.
     *
     *   Optional keys:
     *     - "metadata" (array): Arbitrary context for logging.
     *
     * @return float[] The embedding vector, or an empty array in debug mode.
     *
     * @throws InvalidArgumentException When "text" key is missing.
     * @throws \Throwable               Re-thrown after logging on embedding failure.
     */
    public function embed(array $request): array
    {
        $text =
            $request["text"] ??
            throw new InvalidArgumentException(
                "AgentService::embed — request must include a 'text' key.",
            );

        if ($this->isDisabled()) {
            return [];
        }

        try {
            return $this->embeddings->embedText($text);
        } catch (\Throwable $e) {
            Log::error("AgentService::embed — request failed", [
                "error" => $e->getMessage(),
                "metadata" => $request["metadata"] ?? [],
            ]);
            throw $e;
        }
    }
}
