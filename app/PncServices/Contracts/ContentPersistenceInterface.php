<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncServices\Contracts;

/**
 * Abstraction over content storage (file system now, database later).
 *
 * Implementations must be swappable — services depend on this interface
 * and never call file_get_contents / file_put_contents / DB queries directly.
 *
 * Two usage modes:
 *
 * 1. Stateless — caller provides the full path on every call
 *    (saveJson, readJson, saveText, readText, saveHtml, readHtml).
 *
 * 2. Stateful — caller sets a base directory once via setPath(), then
 *    saves by filename + extension (save, saveJsonTo, saveHtmlTo,
 *    saveMarkdown, append, saveFromPath, saveJsonFromPath, saveSibling).
 */
interface ContentPersistenceInterface
{
    // ─────────────────────────────────────────────────────────────
    // Stateless, full-path API
    // ─────────────────────────────────────────────────────────────

    /**
     * Persists structured data as JSON.
     *
     * @param string $path Target path or storage key (e.g. "/tmp/agents/uuid/content.json")
     * @param array  $data The data to serialize
     */
    public function saveJson(string $path, array $data): void;

    /**
     * Reads and decodes JSON content.
     *
     * @param string $path Source path or storage key
     * @return array|null Decoded data, or null if not found / invalid
     */
    public function readJson(string $path): ?array;

    /**
     * Persists plain text / markdown content.
     *
     * @param string $path    Target path or storage key
     * @param string $content The text to store
     */
    public function saveText(string $path, string $content): void;

    /**
     * Reads text / markdown content.
     *
     * @param string $path Source path or storage key
     * @return string|null Content, or null if not found
     */
    public function readText(string $path): ?string;

    /**
     * Persists HTML content.
     *
     * @param string $path    Target path or storage key
     * @param string $content The HTML to store
     */
    public function saveHtml(string $path, string $content): void;

    /**
     * Reads HTML content.
     *
     * @param string $path Source path or storage key
     * @return string|null Content, or null if not found
     */
    public function readHtml(string $path): ?string;

    // ─────────────────────────────────────────────────────────────
    // Stateful path management
    // ─────────────────────────────────────────────────────────────

    /**
     * Set the base directory for subsequent stateful save calls.
     *
     * @param string $path Base directory path.
     */
    public function setPath(string $path): void;

    /**
     * Get the current base directory.
     *
     * @return string The base directory.
     * @throws \RuntimeException If setPath() has not been called.
     */
    public function path(): string;

    // ─────────────────────────────────────────────────────────────
    // Stateful save methods
    // ─────────────────────────────────────────────────────────────

    /**
     * Save raw data to a file within the base path.
     *
     * @param string $data     Content to write.
     * @param string $filename File name without extension.
     * @param string $ext      File extension (without dot).
     *
     * @return string The full path of the written file.
     */
    public function save(
        string $data,
        string $filename,
        string $ext = "json",
    ): string;

    /**
     * Save data as pretty-printed JSON within the base path.
     *
     * @param string $filename File name without extension.
     * @param array  $data     Data to encode.
     *
     * @return string The full path of the written file.
     */
    public function saveJsonTo(string $filename, array $data): string;

    /**
     * Save HTML content within the base path.
     *
     * @param string $filename File name without extension.
     * @param string $html     HTML content.
     *
     * @return string The full path of the written file.
     */
    public function saveHtmlTo(string $filename, string $html): string;

    /**
     * Save markdown content within the base path.
     *
     * @param string $content  Markdown text.
     * @param string $filename File name without extension.
     *
     * @return string The full path of the written file.
     */
    public function saveMarkdown(string $content, string $filename): string;

    /**
     * Append data to an existing file within the base path.
     *
     * @param string $data     Content to append.
     * @param string $filename File name without extension.
     * @param string $ext      File extension.
     *
     * @return string The full path of the appended file.
     */
    public function append(
        string $data,
        string $filename,
        string $ext = "json",
    ): string;

    // ─────────────────────────────────────────────────────────────
    // Path-derived save helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Save to a path derived from a reference file path.
     *
     * @param string      $data     Content to write.
     * @param string      $fullPath Reference path (e.g. "/out/data/page.json").
     * @param string|null $ext      Override extension, or null to infer.
     *
     * @return string The full path of the written file.
     */
    public function saveFromPath(
        string $data,
        string $fullPath,
        ?string $ext = null,
    ): string;

    /**
     * Save JSON to a path derived from a reference file path.
     *
     * @param array  $data     Data to encode.
     * @param string $fullPath Reference path.
     *
     * @return string The full path of the written file.
     */
    public function saveJsonFromPath(array $data, string $fullPath): string;

    /**
     * Save to a sibling path with a different extension.
     *
     * @param string $data          Content to write.
     * @param string $referencePath The reference file path.
     * @param string $ext           Target extension.
     *
     * @return string The full path of the written file.
     */
    public function saveSibling(
        string $data,
        string $referencePath,
        string $ext,
    ): string;
}
