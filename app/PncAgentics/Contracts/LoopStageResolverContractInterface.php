<?php

declare(strict_types=1);

namespace App\PncAgentics\Contracts;

/**
 * Contract for resolving variables and templates from a workflow context.
 *
 * Implemented by {@see JsonWorkflowExecutor}.
 */
interface LoopStageResolverContractInterface
{
    /**
     * Resolve a single template string from context.
     * Returns the raw value if the string is a single {{key}},
     * otherwise returns the interpolated string.
     *
     * @param string $key     Template string.
     * @param array  $context Current context.
     *
     * @return mixed
     */
    public function resolveVariable(string $key, array $context): mixed;

    /**
     * Replace all {{key}} placeholders in a template with context values.
     *
     * @param string $template Template string.
     * @param array  $context  Current context.
     *
     * @return string
     */
    public function resolveTemplate(string $template, array $context): string;
}
