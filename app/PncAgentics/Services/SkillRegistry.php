<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\SkillRegistryInterface;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Skill registry providing CRUD access to AI agent skill files.
 *
 * Reads from and writes to the `pnc.skills` config array (populated by
 * PncAgenticsServiceProvider from a disk scan of app/PncAgentics/Skills/).
 * Mutations (create, update, delete) persist to disk AND update the
 * in-memory config for the current request.
 *
 * Key conventions:
 *  - Dot-notation keys map to file paths by replacing "." with DIRECTORY_SEPARATOR.
 *  - Keys ending in ".skills" strip that suffix before path resolution.
 *  - All keys are lowercase.
 *
 * Example:
 *  Key:   "track.core_writing.body_content.topic_sentence_opener"
 *  Path:  app/PncAgentics/Skills/track/core_writing/body_content/topic_sentence_opener.md
 *
 * Consumers:
 *  - AIAgentSkillsShow (artisan command)
 *  - SkillController (REST API)
 *  - SkillExecutor (resolves skill content at execution time)
 */
class SkillRegistry implements SkillRegistryInterface
{
    /**
     * Return all registered skills as a key => content map.
     *
     * Delegates to the `pnc.skills` config value, which is resolved
     * lazily by the service provider's singleton (scans disk on first
     * access, then cached by Laravel's config repository).
     *
     * @return array<string, string> Map of dot-notation skill key to markdown content.
     */
    public function all(): array
    {
        return config("pnc.skills");
    }

    /**
     * Return the markdown content for a specific skill key.
     *
     * @param string $key Dot-notation skill key.
     *
     * @return array|null The skill's markdown content, or null if not found.
     */
    public function get(string $skillName): ?array
    {
        $skills = $this->getSkills();
        $skill = $skills[$skillName] ?? null;

        if ($skill === null) {
            return null;
        }

        if (!is_array($skill)) {
            throw new \InvalidArgumentException(
                "Skill '{$skillName}' has invalid definition: expected array, got " .
                    gettype($skill),
            );
        }

        return $skill;
    }

    /**
     * Create a new skill file on disk and register it in the in-memory config.
     *
     * Creates parent directories if they do not exist. Throws if the key
     * already exists — use update() for existing skills.
     *
     * @param string $key     Dot-notation skill key for the new skill.
     * @param string $content Markdown content to write to the skill file.
     *
     * @throws InvalidArgumentException When the skill key already exists.
     * @throws \RuntimeException        When the file cannot be written to disk.
     */
    public function create(string $key, string $content): void
    {
        if ($this->get($key) !== null) {
            throw new InvalidArgumentException(
                "Skill '{$key}' already exists. Use update() to modify.",
            );
        }

        $skills = $this->getSkills();
        $skills[$key] = [
            "name" => $key,
            "system_instructions" => $content,
        ];
        config()->set("pnc.skills", $skills);

        $path = $this->keyToPath($key);
        File::makeDirectory(dirname($path), 0755, true, true);
        File::put($path, $content);
    }

    /**
     * Update the content of an existing skill file.
     *
     * Overwrites the file on disk and refreshes the in-memory config entry.
     *
     * @param string $key     Dot-notation skill key of the existing skill.
     * @param string $content New markdown content to replace the current file.
     *
     * @throws InvalidArgumentException When the skill key does not exist.
     * @throws \RuntimeException        When the file cannot be written to disk.
     */
    public function update(string $key, string $content): void
    {
        if ($this->get($key) === null) {
            throw new InvalidArgumentException(
                "Skill '{$key}' does not exist.",
            );
        }

        $skills = $this->getSkills();
        $skills[$key]["system_instructions"] = $content;
        config()->set("pnc.skills", $skills);

        $path = $this->keyToPath($key);
        File::put($path, $content);
    }

    /**
     * Delete a skill file from disk and remove it from the in-memory config.
     *
     * @param string $key Dot-notation skill key of the skill to delete.
     *
     * @throws InvalidArgumentException When the skill key does not exist.
     * @throws \RuntimeException        When the file cannot be deleted from disk.
     */
    public function delete(string $key): void
    {
        if ($this->get($key) === null) {
            throw new InvalidArgumentException(
                "Skill '{$key}' does not exist.",
            );
        }

        $skills = $this->getSkills();
        unset($skills[$key]);
        config()->set("pnc.skills", $skills);

        $path = $this->keyToPath($key);
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * Convert a dot-notation skill key to an absolute file path.
     *
     * Replaces dots with directory separators and appends ".md".
     * Keys ending in ".skills" have that suffix stripped before resolution
     * (matching the convention used by PncAgenticsServiceProvider's scanner).
     *
     * @param string $key Dot-notation skill key.
     *
     * @return string Absolute path to the skill file on disk.
     */
    protected function keyToPath(string $key): string
    {
        // Strip ".skills" suffix if present (matches provider scan logic).
        if (str_ends_with($key, ".skills")) {
            $key = substr($key, 0, -7);
        }

        $relative = str_replace(".", DIRECTORY_SEPARATOR, $key) . ".md";
        return app_path("PncAgentics/Skills/" . $relative);
    }

    /**
     * Resolve the skills array from config (handles the lazy closure).
     *
     * @return array<string, array>
     */
    private function getSkills(): array
    {
        $skills = config("pnc.skills");
        if ($skills instanceof \Closure) {
            $skills = $skills();
        }
        return $skills;
    }
}
