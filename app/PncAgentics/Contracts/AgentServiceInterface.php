<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Contracts;

/**
 * Contract for the AI agent service.
 *
 * Defines the stable interface for all LLM interactions in the application.
 * Each method that sends a message to the LLM accepts two distinct inputs:
 *  - $system: the system prompt (role/instruction for the model)
 *  - $prompt / $message: the user message (actual content to process)
 *
 * When $system is null, the default system instruction from
 * PncAgent::instructions() (config: pncservices.ai.system_prompt) is used.
 *
 * Implementations:
 *  - App\PncAgentics\Services\AgentService (production, backed by NeuronAI)
 *
 * Consumers:
 *  - SkillExecutor (one-shot completions via complete())
 *  - Any future multi-turn chat features (via chat())
 *  - RAG pipelines (via embed())
 */
interface AgentServiceInterface
{
    /**
     * @param array $request Must include "system_prompt" and "user_prompt".
     *                       Optional: "temperature", "max_tokens", "metadata".
     */
    public function complete(array $request): string;

    /**
     * @param array $request Must include "system_prompt" and "user_prompt".
     *                       Optional: "thread_id", "temperature", "max_tokens", "metadata".
     */
    public function chat(array $request): string;

    /**
     * Generate a vector embedding for the given text.
     *
     * @param array $request array text to embed.
     *
     * @return float[] The embedding vector.
     *
     * @throws \Throwable Re-thrown after logging on embedding failure.
     */
    public function embed(array $request): array;
}
