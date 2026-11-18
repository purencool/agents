<?php

declare(strict_types=1);

namespace App\PncAgentics\Data;

/**
 * Immutable value object holding LLM configuration options.
 *
 * Encapsulates the parameters that control how a skill prompt is
 * sent to the AI service (temperature, token limit, and any
 * additional system-level rules).
 */
class SkillOptions
{
    /**
     * @param float $temperature     Sampling temperature (0.0–1.0). Higher values produce more creative output.
     * @param int   $maxTokens       Maximum number of tokens in the LLM response.
     * @param array $customSystemRules Additional rules appended to the system prompt.
     */
    public function __construct(
        public float $temperature = 0.7,
        public int $maxTokens = 4000,
        public array $customSystemRules = [],
    ) {}

    /**
     * Create a SkillOptions instance from a loosely-typed associative array.
     *
     * Accepts the keys "temperature", "max_tokens", and "system_rules".
     * Missing keys fall back to the constructor defaults.
     *
     * @param array $options Associative array of LLM options.
     *
     * @return self
     */
    public static function fromArray(array $options): self
    {
        return new self(
            temperature: (float) ($options["temperature"] ?? 0.7),
            maxTokens: (int) ($options["max_tokens"] ?? 4000),
            customSystemRules: $options["system_rules"] ?? [],
        );
    }
}
