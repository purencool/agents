<?php

namespace App\PncAgentics\Contracts;

interface SkillExecutorInterface
{
    /**
     * Executes a specific markdown-based skill by injecting
     * parameters and running it through the AI.
     */
    public function execute(string $skillKey, array $parameters = []): string;
}
