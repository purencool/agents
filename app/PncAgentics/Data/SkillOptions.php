<?php

namespace App\PncAgentics\Data;

class SkillOptions
{
    public function __construct(
        public float $temperature = 0.7,
        public int $maxTokens = 4000,
        public array $customSystemRules = [],
    ) {}

    public static function fromArray(array $options): self
    {
        return new self(
            temperature: $options["temperature"] ?? 0.7,
            maxTokens: $options["max_tokens"] ?? 4000,
            customSystemRules: $options["system_rules"] ?? [],
        );
    }
}
