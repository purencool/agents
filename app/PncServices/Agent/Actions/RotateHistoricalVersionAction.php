<?php

namespace App\PncServices\Agent\Actions;

class RotateHistoricalVersionAction
{
    /**
     * Executes the incremental backup version rotation loop on disk.
     *
     * @param string $targetDirectory The absolute asset folder path
     * @param string $masterPath The live production master file path
     * @param string $currentContent The current raw file text context
     * @return int The compiled version iteration number allocated
     */
    public function execute(
        string $targetDirectory,
        string $masterPath,
        string $currentContent,
    ): int {
        if (!file_exists($masterPath) || empty($currentContent)) {
            return 0;
        }

        $versionCounter = 1;

        // Loop continuously to find the first open available version slot
        while (file_exists("{$targetDirectory}/master-v{$versionCounter}.md")) {
            $versionCounter++;
        }

        $archivePath = "{$targetDirectory}/master-v{$versionCounter}.md";

        // Backup the original content safely to its allocated version index
        copy($masterPath, $archivePath);

        // Mirror the archive tracking backup directly down into your dev
        // workspace branch as well
        if (is_dir("{$targetDirectory}/dev")) {
            copy(
                $masterPath,
                "{$targetDirectory}/dev/master-v{$versionCounter}.md",
            );
        }

        return $versionCounter;
    }
}
