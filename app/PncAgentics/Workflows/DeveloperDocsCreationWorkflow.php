<?php

namespace App\PncAgentics\Workflows;

use App\PncAgentics\Contracts\WorkflowInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;

class DeveloperDocsCreationWorkflow implements WorkflowInterface
{
    public function __construct(protected SkillExecutorInterface $executor) {}

    public function execute(
        array $payload,
        string $targetDirectory,
        ?string $existingContent = null,
    ): string {
        // Dynamically resolve target sub-skills under developer_docs (e.g., reference, tutorials, how_to_guides)
        $subType = $payload["sub_type"] ?? "tutorials";
        $skillKey = "developer_docs.{$subType}";

        $rawDoc = $this->executor->execute($skillKey, $payload);

        // Pass through general developer polish rules
        return $this->executor->execute("developer_docs.explanation", [
            "content" => $rawDoc,
        ]);
    }
}
