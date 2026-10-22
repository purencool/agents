<?php

namespace App\PncServices\Agent\Actions;

use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReframeAgentContentAction
{
    public function __construct(
        protected WorkflowFactoryInterface $workflowFactory,
    ) {}

    /**
     * Orchestrates the locating, dual version archiving,
     * prompt overwriting, context merging, and AI execution.
     */
    public function execute(string $trackType, array $payload): array
    {
        $domainSlug = Str::slug($payload["domain"], "_");
        $typeSlug = Str::slug($payload["type"], "_");
        $titleSlug = Str::slug($payload["title"], "_");
        $trackSlug = Str::slug($trackType, "_");

        $overlayBaseStore = env("OVERLAY_OVERRIDES");
        $baseDataStore = $overlayBaseStore
            ? realpath(base_path($overlayBaseStore) . "/../data")
            : base_path("../data/agents/data");

        if (empty($baseDataStore) || !$baseDataStore) {
            $baseDataStore = base_path("../data/agents/data");
        }

        $trackDirectory = "{$baseDataStore}/{$domainSlug}/{$trackSlug}/{$typeSlug}";

        if (!is_dir($trackDirectory)) {
            throw new InvalidArgumentException(
                "Target folder hierarchy node [{$trackDirectory}] does not exist on disk.",
            );
        }

        $targetDirectory = null;
        $dateDirectories = array_filter(glob("{$trackDirectory}/*"), "is_dir");

        foreach ($dateDirectories as $dateDir) {
            if (basename($dateDir) === "config") {
                continue;
            }
            if (is_dir("{$dateDir}/{$titleSlug}")) {
                $targetDirectory = "{$dateDir}/{$titleSlug}";
                break;
            }
        }

        if (!$targetDirectory) {
            throw new InvalidArgumentException(
                "No installed content file footprint found.",
            );
        }

        $rootMasterPath = "{$targetDirectory}/master.md";
        $devMasterPath = "{$targetDirectory}/dev/master.md";
        $rootVersion = 1;
        if (file_exists($rootMasterPath)) {
            while (
                file_exists("{$targetDirectory}/master-v{$rootVersion}.md")
            ) {
                $rootVersion++;
            }
            copy(
                $rootMasterPath,
                "{$targetDirectory}/master-v{$rootVersion}.md",
            );
        }

        $devVersion = 1;
        if (file_exists($devMasterPath)) {
            while (
                file_exists("{$targetDirectory}/dev/master-v{$devVersion}.md")
            ) {
                $devVersion++;
            }
            copy(
                $devMasterPath,
                "{$targetDirectory}/dev/master-v{$devVersion}.md",
            );
        }

        $currentDate = now()->format("Y-m-d");
        $newPromptContent = "# {$payload["title"]}\n\n## Description\n{$payload["description"]}\n\n---\nCreated automatically via Pnc Agent Engine on {$currentDate}.";
        file_put_contents($rootMasterPath, $newPromptContent);

        $freshRootPrompt = file_get_contents($rootMasterPath);

        $archivedDevBackupPath = "{$targetDirectory}/dev/master-v{$devVersion}.md";
        $historicalContentText = file_exists($archivedDevBackupPath)
            ? file_get_contents($archivedDevBackupPath)
            : "";

        $mergedWorkflowContext =
            trim($freshRootPrompt) .
            "\n\n" .
            "=== PREVIOUS GENERATION CONTENT CANVAS (FOR REFINEMENT) ===\n" .
            trim($historicalContentText);

        $trackPayload = [
            "domain" => $payload["domain"],
            "type" => $payload["type"],
            "title" => $payload["title"],
            "description" => $payload["description"],
            "content_track" => $trackType,
        ];

        $resolved = $this->workflowFactory->make($trackType, $isUpdate = true);
        $workflow = $resolved["workflow"];
        $baseSkillKey = $resolved["base_key"];
        $markdownResult = $workflow->execute(
            $baseSkillKey,
            $trackPayload,
            $targetDirectory,
            $mergedWorkflowContext,
        );

        if (is_dir("{$targetDirectory}/dev")) {
            file_put_contents($devMasterPath, $markdownResult);
        }
        if (is_dir("{$targetDirectory}/stage")) {
            file_put_contents(
                "{$targetDirectory}/stage/master.md",
                $markdownResult,
            );
        }

        $trackPayload["updated_at"] = now()->toIso8601String();
        $trackPayload["version_iteration"] = $devVersion;

        file_put_contents(
            "{$targetDirectory}/payload.json",
            json_encode(
                $trackPayload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        return [
            "status" => "success",
            "target_directory" => $targetDirectory,
            "version_count" => $devVersion,
            "base_skill_key" => $baseSkillKey,
        ];
    }
}
