<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncServices\Storage;

use App\PncServices\Contracts\ContentPersistenceInterface;
use RuntimeException;

/**
 * File-system backed implementation of ContentPersistenceInterface.
 *
 * Combines read/write persistence (ContentPersistenceInterface) with
 * convenience file-saving utilities.
 *
 * Two usage modes:
 *
 * 1. Stateless (ContentPersistenceInterface methods) — caller provides
 *    the full absolute path on every call.
 *
 * 2. Stateful (save / saveJson / saveHtml / saveMarkdown / append, etc.)
 *    — caller sets a base directory once via setPath(), then saves by
 *    filename + extension. The base path is held on the instance.
 *
 * All paths are absolute. Parent directories are created on write
 * if they don't already exist.
 *
 * Supported formats:
 *  - JSON  (saveJson / readJson)
 *  - Plain text (saveText / readText)
 *  - HTML   (saveHtml / readHtml)
 *  - Markdown (saveMarkdown)
 *
 * All read methods return null when the file does not exist or
 * contains unparseable content.
 */
class FileContentPersistence implements ContentPersistenceInterface
{
    /**
     * Base directory for stateful save operations.
     */
    private ?string $basePath = null;

    // ─────────────────────────────────────────────────────────────
    // ContentPersistenceInterface — stateless, full-path API
    // ─────────────────────────────────────────────────────────────

    /**
     * Save data as pretty-printed JSON to the given path.
     *
     * @param string $path Absolute file path.
     * @param array  $data Data to encode as JSON.
     */
    public function saveJson(string $path, array $data): void
    {
        $this->ensureDirectory($path);
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Read and decode a JSON file.
     *
     * @param string $path Absolute file path.
     *
     * @return array|null Decoded associative array, or null if the file
     *                    doesn't exist or contains invalid JSON.
     */
    public function readJson(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $decoded = json_decode(file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Save plain text content to the given path.
     *
     * @param string $path    Absolute file path.
     * @param string $content Text content to write.
     */
    public function saveText(string $path, string $content): void
    {
        $this->ensureDirectory($path);
        file_put_contents($path, $content);
    }

    /**
     * Read the full text content of a file.
     *
     * @param string $path Absolute file path.
     *
     * @return string|null File contents, or null if the file doesn't exist.
     */
    public function readText(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return $content !== false ? $content : null;
    }

    /**
     * Save HTML content to the given path.
     *
     * @param string $path    Absolute file path.
     * @param string $content HTML content to write.
     */
    public function saveHtml(string $path, string $content): void
    {
        $this->ensureDirectory($path);
        file_put_contents($path, $content);
    }

    /**
     * Read HTML content from a file.
     *
     * @param string $path Absolute file path.
     *
     * @return string|null HTML content, or null if the file doesn't exist.
     */
    public function readHtml(string $path): ?string
    {
        return $this->readText($path);
    }

    /**
     * Set the base directory for subsequent stateful save calls.
     *
     * Creates the directory if it doesn't exist.
     *
     * @param string $path Base directory path (trailing slash stripped).
     */
    public function setPath(string $path): void
    {
        $this->basePath = rtrim($path, "/");
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    /**
     * Get the current base directory.
     *
     * @throws RuntimeException If setPath() has not been called.
     */
    public function path(): string
    {
        if ($this->basePath === null) {
            throw new RuntimeException(
                "FileContentPersistence path not set. Call setPath() first.",
            );
        }
        return $this->basePath;
    }

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
    ): string {
        $path = "{$this->path()}/{$filename}.{$ext}";
        $this->ensureDirectory($path);
        file_put_contents($path, $data);
        return $path;
    }

    /**
     * Save data as pretty-printed JSON within the base path.
     *
     * @param array  $data     Data to encode.
     * @param string $filename File name without extension.
     *
     * @return string The full path of the written file.
     */
    public function saveJsonTo(string $filename, array $data): string
    {
        return $this->save(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $filename,
            "json",
        );
    }

    /**
     * Save HTML content within the base path.
     *
     * @param string $html     HTML content.
     * @param string $filename File name without extension.
     *
     * @return string The full path of the written file.
     */
    public function saveHtmlTo(string $filename, string $html): string
    {
        return $this->save($html, $filename, "html");
    }

    /**
     * Save markdown content within the base path.
     *
     * @param string $content  Markdown text.
     * @param string $filename File name without extension.
     *
     * @return string The full path of the written file.
     */
    public function saveMarkdown(string $content, string $filename): string
    {
        return $this->save($content, $filename, "md");
    }

    /**
     * Append data to an existing file within the base path.
     *
     * Useful for logs or accumulating results.
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
    ): string {
        $path = "{$this->path()}/{$filename}.{$ext}";
        $this->ensureDirectory($path);
        file_put_contents($path, $data, FILE_APPEND);
        return $path;
    }

    /**
     * Save to a path derived from a reference file path.
     *
     * Strips the extension from the reference path's basename, sets
     * the directory as the base path, and saves with the given extension.
     *
     * @param string   $data     Content to write.
     * @param string   $fullPath Reference path (e.g. "/out/data/page.json").
     * @param string|null $ext   Override extension, or null to infer from fullPath.
     *
     * @return string The full path of the written file.
     */
    public function saveFromPath(
        string $data,
        string $fullPath,
        ?string $ext = null,
    ): string {
        $dir = dirname($fullPath);
        $filename = basename($fullPath);
        $filename = preg_replace('/\.(json|html|md|txt)$/', "", $filename);

        if ($ext === null) {
            $ext = pathinfo($fullPath, PATHINFO_EXTENSION) ?: "json";
        }

        $this->setPath($dir);
        return $this->save($data, $filename, $ext);
    }

    /**
     * Save JSON to a path derived from a reference file path.
     *
     * @param array  $data     Data to encode.
     * @param string $fullPath Reference path.
     *
     * @return string The full path of the written file.
     */
    public function saveJsonFromPath(array $data, string $fullPath): string
    {
        return $this->saveFromPath(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $fullPath,
            "json",
        );
    }

    /**
     * Save to a sibling path with a different extension.
     *
     * e.g. saveSibling($html, '/out/data/mcp/page.json', 'html') → /out/data/mcp/page.html
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
    ): string {
        $dir = dirname($referencePath);
        $base = pathinfo($referencePath, PATHINFO_FILENAME);
        $this->setPath($dir);
        return $this->save($data, $base, $ext);
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Create the parent directory of the given path if it doesn't exist.
     *
     * @param string $path Absolute file path.
     */
    private function ensureDirectory(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
