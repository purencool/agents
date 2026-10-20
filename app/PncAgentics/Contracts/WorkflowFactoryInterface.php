<?php

namespace App\PncAgentics\Contracts;

interface WorkflowFactoryInterface
{
    public function make(
        string $type,
        bool $isUpdate = false,
    ): WorkflowInterface;
}
