<?php

namespace App\PncAgentics\Contracts;

interface WorkflowInterface
{
    /**
     * Executes the multi-agent prompt loops for a specific domain pipeline.
     */
    public function execute(
        string $baseSkillKey,
        array $payload,
        string $targetDirectory,
        ?string $existingContent = null,
    ): string;
}
