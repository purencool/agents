<?php

namespace App\PncAgentics\Contracts;

interface DocumentationWorkflowInterface
{
    /**
     * Run the multi-agent generation pipeline.
     *
     * @param array $payload The raw validated data array (domain, type, title, description)
     * @return bool True if the workflow completed successfully, false otherwise
     */
    public function run(array $payload): bool;
}
