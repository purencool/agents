<?php

namespace App\PncAgentics\Factory;

use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use App\PncAgentics\Contracts\WorkflowInterface;
use App\PncAgentics\Workflows\AiAgentsCreationWorkflow;
use App\PncAgentics\Workflows\DeveloperDocsCreationWorkflow;
use App\PncAgentics\Workflows\ProductGuidesCreationWorkflow;
use App\PncAgentics\Workflows\UpdateRefiningWorkflow;
use InvalidArgumentException;

class WorkflowFactory implements WorkflowFactoryInterface
{
    public function make(
        string $type,
        bool $isUpdate = false,
    ): WorkflowInterface {
        // If it's an update process, bypass normal generation routes and target the refinement engine
        if ($isUpdate) {
            return app(UpdateRefiningWorkflow::class);
        }

        // Determine creation strategies based on the category path mapping passed via JSON payload
        return match ($type) {
            "ai_agents" => app(AiAgentsCreationWorkflow::class),
            "developer_docs" => app(DeveloperDocsCreationWorkflow::class),
            "product_guides" => app(ProductGuidesCreationWorkflow::class),
            default => throw new InvalidArgumentException(
                "Unsupported workflow type: [{$type}]",
            ),
        };
    }
}
