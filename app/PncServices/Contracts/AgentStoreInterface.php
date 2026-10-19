<?php

namespace App\PncServices\Contracts;

interface AgentStoreInterface
{
    /**
     * Verifies and generates the agent filesystem hierarchy.
     *
     * @param array $data Validated payload arrays
     * @return string The newly generated structural absolute path
     */
    public function createStructure(array $data): string;
}
