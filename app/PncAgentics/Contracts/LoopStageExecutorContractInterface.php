<?php

declare(strict_types=1);

namespace App\PncAgentics\Contracts;

/**
 * Contract for executing a single workflow stage.
 *
 * Implemented by {@see JsonWorkflowExecutor}.
 */
interface LoopStageExecutorContractInterface
{
    /**
     * Execute a single linear stage, storing the result in context.
     *
     * @param array $stage   Stage definition.
     * @param array $context Mutable context (modified in-place).
     */
    public function executeStage(array $stage, array &$context): void;
}
