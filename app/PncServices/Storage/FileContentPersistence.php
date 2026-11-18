<?php

declare(strict_types=1);

namespace App\PncServices\Storage;

use App\PncServices\Contracts\ContentPersistenceInterface;

/**
 * File-system backed implementation of ContentPersistenceInterface.
 *
 * All paths are absolute. Parent directories are created on write
 * if they don't already exist.
 *
 * Supported formats:
 *  - JSON  (saveJson / readJson)
 *  - Plain text (saveText / readText)
 *  - HTML   (saveHtml / readHtml — delegates to readText)
 *
 * All read methods return null when the file does not exist or
 * contains unparseable content.
 */
class FileContentPersistence implements ContentPersistenceInterface
{
    /**
     * Save data as pretty-printed JSON to the given path.
     *
     * Creates parent directories if they don't exist.
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
     * @return string|null File contents, or null if the file doesn't exist
     *                     or cannot be read.
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
     * Delegates to readText() — HTML is stored as plain text.
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
     * Create the parent directory of the given path if it doesn't exist.
     *
     * Uses recursive mkdir with 0755 permissions.
     *
     * @param string $path Absolute file path (directory is derived via dirname).
     */
    private function ensureDirectory(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
