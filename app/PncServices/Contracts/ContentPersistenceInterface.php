<?php

declare(strict_types=1);

namespace App\PncServices\Contracts;

/**
 * Abstraction over content storage (file system now, database later).
 *
 * Implementations must be swappable — services depend on this interface
 * and never call file_get_contents / file_put_contents / DB queries directly.
 */
interface ContentPersistenceInterface
{
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
     * @param string $path     Target path or storage key
     * @param string $content  The text to store
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
}
