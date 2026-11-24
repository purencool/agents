<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Workflows;

use Illuminate\Support\Facades\File;

/**
 * Resolves and loads workflow definition JSON files from disk.
 *
 * Resolution order (first match wins):
 *  1. OVERLAY_WORKFLOWS env var — set by the integration team via the
 *     container's .env file (Docker env_file). Points to a host-mounted
 *     volume for custom/overridden workflows.
 *  2. config("pnc.workflows.override_dir") — application-level override
 *     (e.g. for per-environment tweaks set in config/pnc.php).
 *  3. Default definitions directory (baked into the image).
 *
 * Fallback behaviour:
 *  If the requested workflow file is not found in any directory, the loader
 *  falls back to "default.json" in the default definitions directory.
 *  An exception is only thrown when neither the named file nor default.json
 *  exists.
 *
 * @example
 *   $loader = new WorkflowDefinitionLoader("/path/to/definitions");
 *   $definition = $loader->load("universal_documentation");
 *   // If "<foo>.json" doesn't exist, loads "default.json".
 */
class WorkflowDefinitionLoader
{
    protected string $definitionsDir;

    /**
     * Load a workflow definition by name.
     *
     * Searches the overlay, config override, and default directories in
     * order. If the named file is not found anywhere, falls back to
     * "default.json" in the default definitions directory.
     *
     * @param string $workflowName Workflow identifier (filename without .json).
     *
     * @return array The decoded JSON workflow definition.
     *
     * @throws \RuntimeException When neither the named file nor default.json exists.
     * @throws \RuntimeException When the file contains invalid JSON.
     */
    public function load(string $workflowName): array
    {
        $this->definitionsDir = config("pnc.workflows.definitions_dir");

        $fileName = "{$workflowName}.json";

        // Check override directory (set via OVERLAY_WORKFLOWS env → config).
        $configOverride = config("pnc.workflows.override_dir");
        if ($configOverride) {
            $configPath =
                base_path($configOverride) . DIRECTORY_SEPARATOR . $fileName;
            if (File::exists($configPath)) {
                return $this->decode($configPath);
            }
        }

        // Check the default definitions directory.
        $defaultPath = $this->definitionsDir . DIRECTORY_SEPARATOR . $fileName;
        if (File::exists($defaultPath)) {
            return $this->decode($defaultPath);
        }

        //  Fallback: try blog_post.json in the definitions directory.
        $fallbackPath =
            $this->definitionsDir . DIRECTORY_SEPARATOR . "blog_post.json";
        if (File::exists($fallbackPath)) {
            return $this->decode($fallbackPath);
        }

        // Nothing found — throw.
        throw new \RuntimeException(
            "Workflow definition not found: {$fileName}. " .
                "Fallback default.json also not found. " .
                "Searched: " .
                (string) ($configOverride
                    ? base_path($configOverride)
                    : "(no override)") .
                ", " .
                $this->definitionsDir,
        );
    }

    /**
     * Decode a JSON file into an array.
     *
     * @param string $path Absolute path to the JSON file.
     *
     * @return array The decoded workflow definition.
     *
     * @throws \RuntimeException When the file contains invalid JSON.
     */
    protected function decode(string $path): array
    {
        $data = json_decode(File::get($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "Invalid JSON in workflow definition: {$path} — " .
                    json_last_error_msg(),
            );
        }

        return $data;
    }
}
