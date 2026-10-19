<?php

namespace App\PncServices\Agent;

use App\PncServices\Contracts\AgentStoreInterface;
use App\PncServices\Agent\Actions\CreateAgentStructureAction;

class AgentStoreService implements AgentStoreInterface
{
    /**
     * Inject the specific action via the constructor.
     */
    public function __construct(
        protected CreateAgentStructureAction $createStructureAction,
    ) {}

    /**
     * Verifies and generates the agent filesystem hierarchy.
     */
    public function createStructure(array $data): string
    {
        return $this->createStructureAction->execute($data);
    }

    // Future methods live here cleanly without bloating the creation logic:
    // public function removeStructure(string $id): bool { ... }
    // public function syncAgentMetadata(array $data): void { ... }
}
