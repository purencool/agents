<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Contracts;

use InvalidArgumentException;

/**
 * Contract for the skill registry.
 *
 * Provides CRUD access to AI agent skill files (markdown prompts stored
 * on disk under app/PncAgentics/Skills/). The registry is the single
 * source of truth for skill content and is consumed by:
 *
 *  - AIAgentSkillsShow (artisan command — CLI display)
 *  - SkillController (REST API — JSON responses)
 *  - SkillExecutor (resolves skill content at execution time)
 *
 * Keys use dot-notation (e.g. "track.core_writing.body_content.topic_sentence_opener")
 * and map to file paths by replacing dots with directory separators.
 *
 * Implementations:
 *  - App\PncAgentics\Services\SkillRegistry
 */
interface SkillRegistryInterface
{
    /**
     * Return all registered skills as a key => content map.
     *
     * The returned array maps each dot-notation skill key to the raw
     * markdown content of its file. The result is cached for the
     * duration of the request.
     *
     * @return array<string, string> Map of skill key to markdown content.
     */
    public function all(): array;

    /**
     * Return the markdown content for a specific skill key.
     *
     * @param string $key Dot-notation skill key
     *                    (e.g. "track.core_writing.body_content.topic_sentence_opener").
     *
     * @return string|null The skill's markdown content, or null if the key does not exist.
     */
    public function get(string $key): ?string;

    /**
     * Create a new skill file on disk and register it in the in-memory config.
     *
     * Creates parent directories if they do not exist. Fails silently if
     * the key already exists (use update() for existing skills).
     *
     * @param string $key     Dot-notation skill key for the new skill.
     * @param string $content Markdown content to write to the skill file.
     *
     * @throws InvalidArgumentException When the skill key already exists.
     * @throws \RuntimeException        When the file cannot be written to disk.
     */
    public function create(string $key, string $content): void;

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
    public function update(string $key, string $content): void;

    /**
     * Delete a skill file from disk and remove it from the in-memory config.
     *
     * @param string $key Dot-notation skill key of the skill to delete.
     *
     * @throws InvalidArgumentException When the skill key does not exist.
     * @throws \RuntimeException        When the file cannot be deleted from disk.
     */
    public function delete(string $key): void;
}
