<?php

namespace App\PncAgentics\Contracts;

interface WorkflowInterface
{
    /**
     * Executes a specific documentation generation or modification lifecycle sequence.
     */
    public function execute(
        array $payload,
        string $targetDirectory,
        ?string $existingContent = null,
    ): string;
}
