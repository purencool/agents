<?php

namespace App\PncServices\Contracts;

interface AgentDataInterface
{
    /**
     * Verifies and generates the agent filesystem hierarchy.
     *
     * @param array $data Validated payload (title, description, domains)
     * @return array Structure with uuid, paths, and track file mappings
     */
    public function createStructure(array $data): array;

    /**
     * Applies template-based content generation to the given structure.
     *
     * @param array $structure Return value from createStructure()
     * @return array Results per track
     */
    public function applyTemplate(array $structure): array;

    /**
     * Runs research content generation for the given structure.
     *
     * @param array $structure Return value from createStructure()
     * @return array Results per track
     */
    public function runResearch(array $structure): array;

    /**
     * Executes the full workflow pipeline for the given structure.
     *
     * @param array $structure Return value from createStructure()
     * @return array Results per track
     */
    public function runWorkFlow(array $structure): array;

    /**
     * Runs the full pipeline (structure → template → research → workflow) in sequence.
     *
     * @param array $data Validated payload
     * @return array Combined results from all pipeline stages
     */
    public function executeFullCreationPipeline(array $data): array;
}
