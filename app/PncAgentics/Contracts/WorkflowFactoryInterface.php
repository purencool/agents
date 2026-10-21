<?php

namespace App\PncAgentics\Contracts;

interface WorkflowFactoryInterface
{
    /**
     * Inspects configuration handles and returns a dynamic data map.
     *
     * @return array{workflow: \App\PncAgentics\Contracts\WorkflowInterface, base_key: string}
     */
    public function make(string $type, bool $isUpdate = false): array;
}
