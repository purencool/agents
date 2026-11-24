<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Actions;

use App\PncAgentics\Workflows\JsonWorkflowExecutor;

/**
 * Executes the resolved workflow for each track and attaches the output
 * to the content structure.
 *
 * Acts as a thin wrapper between the upstream pipeline (which builds the
 * structure) and the JsonWorkflowExecutor (which runs the JSON-defined
 * stages). For each track, it:
 *  1. Determines the workflow name from the track's "workflow" key.
 *  2. Builds the context array from the structure + track fields.
 *  3. Calls JsonWorkflowExecutor::execute().
 *  4. Stores the result in the track under "content".
 *
 * The structure is returned with "content" added to each track.
 * All top-level keys are passed through unmodified.
 */
class WorkFlowAgentContentAction
{
    /**
     * @param JsonWorkflowExecutor $executor The workflow execution engine.
     */
    public function __construct(private JsonWorkflowExecutor $executor)
    {
        //
    }

    /**
     * Execute the workflow for each track and attach the output.
     *
     * @param array  $structure Return value from {@see self::createStructure()}.
     *
     * @return array<string, mixed> The same structure with "content" added to each track.
     */
    public function execute(array $structure): array
    {
        return $this->executor->execute($structure);
    }
}
