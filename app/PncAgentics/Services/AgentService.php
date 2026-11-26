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

/**
 * Orchestration layer for all AI/LLM interactions.
 *
 * This service is a **pure orchestrator**. It contains zero NeuronAI
 * imports — no message types, no provider classes, no embedding clients.
 * All LLM mechanics (provider config, chat history, message construction,
 * response extraction, embedding calls) are delegated to {@see PncAgent}.
 *
 * Responsibility split:
 *  - AgentService (this class): input validation, debug-mode guard,
 *    logging, error handling, delegation.
 *  - PncAgent: NeuronAI provider, chat history, UserMessage construction,
 *    thread management, response extraction, embedding client.
 *
 * Request array contract:
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
 *  store inside PncAgent. The caller passes a stable thread_id for each
 *  conversation; PncAgent loads prior messages, trims them to fit the
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
    // ─────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Generate a one-shot completion from the AI model.
     *
     * Stateless single-turn call. Delegates to PncAgent::complete() which
     * handles thread setup, message construction, and response extraction.
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
            $skill = $request["metadata"]["skill_executed"] ?? "unknown";
            $sysPreview = mb_substr($request["system_prompt"], 0, 120);
            return "[DEBUG] {$skill}\n  sys: {$sysPreview}...";
        }

        $agent = $this->createAgent();

        try {
            $content = $agent->complete(
                $prompts["system_prompt"],
                $prompts["user_prompt"],
                $this->extractOptions($request),
            );

            if ($content === "") {
                Log::warning(
                    "AgentService::complete — model returned empty content",
                    ["metadata" => $request["metadata"] ?? []],
                );
            }

            return $content;
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
     * Multi-turn conversation is handled by NeuronAI's chat history store
     * inside PncAgent. The caller passes a stable thread_id for each
     * conversation; PncAgent loads prior messages, trims them to fit the
     * context window, sends the full context to the LLM, and persists the
     * new turn automatically.
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
            return "[DEBUG] chat ({$threadId}) — pipeline OK, no LLM call made.";
        }

        $agent = $this->createAgent();

        try {
            return $agent->chatOnThread(
                $threadId,
                $prompts["system_prompt"],
                $prompts["user_prompt"],
                $this->extractOptions($request),
            );
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
     * Delegates to PncAgent::embed() which holds the OpenAILikeEmbeddings
     * client configured with the embedding model (separate from the chat
     * model) and the /embeddings endpoint.
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
            return $this->createAgent()->embed($text);
        } catch (\Throwable $e) {
            Log::error("AgentService::embed — request failed", [
                "error" => $e->getMessage(),
                "metadata" => $request["metadata"] ?? [],
            ]);
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Protected helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Determine whether the AI service is in debug (no-op) mode.
     *
     * When AI_ENABLE is set to a falsy value ("false", "0", "no", "off")
     * in the environment, all LLM calls are suppressed. A debug log entry
     * is written identifying which method triggered the guard.
     *
     * This check runs AFTER validation but BEFORE any agent creation,
     * so the contract is enforced even when the LLM is disabled.
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
     * Overridable for testing (e.g. to inject a mock). The agent
     * configures its own provider, chat history, and embedding client
     * internally — no options are passed at construction time.
     *
     * @return PncAgent A new agent instance.
     */
    protected function createAgent(): PncAgent
    {
        return new PncAgent();
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
     * Extract optional LLM parameters from the request array.
     *
     * Returns only the keys that are present and non-null, ready to be
     * passed to PncAgent::complete() or PncAgent::chatOnThread().
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
        $sep = str_repeat("=", 60);

        return <<<LOG
        {$sep}
        DEBUG MODE — AI Request
        Thread: {$threadId}
        {$sep}
        [SYSTEM PROMPT]
        {$systemPrompt}
        {$sep}
        [USER PROMPT]
        {$userPrompt}
        {$sep}
        LOG;
    }
}
