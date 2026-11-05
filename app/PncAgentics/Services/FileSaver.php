<?php

namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\FileSaverInterface;
use RuntimeException;

class FileSaver implements FileSaverInterface
{
    private ?string $researchPath = null;

    public function setPath(string $path): void
    {
        $this->researchPath = rtrim($path, '/');
        if (!is_dir($this->researchPath)) {
            mkdir($this->researchPath, 0755, true);
        }
    }

    public function path(): string
    {
        if (!$this->researchPath) {
            throw new RuntimeException("FileSaver path not set. Call setPath() first.");
        }
        return $this->researchPath;
    }

    public function save(string $data, string $filename, string $ext = 'json'): string
    {
        $path = "{$this->path()}/{$filename}.{$ext}";
        file_put_contents($path, $data);
        return $path;
    }

    public function saveJson(array $data, string $filename): string
    {
        return $this->save(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $filename,
            'json',
        );
    }

    public function saveHtml(string $html, string $filename): string
    {
        return $this->save($html, $filename, 'html');
    }

    public function saveMarkdown(string $content, string $filename): string
    {
        return $this->save($content, $filename, 'md');
    }

    public function saveFromPath(string $data, string $fullPath, ?string $ext = null): string
    {
        $dir = dirname($fullPath);
        $filename = basename($fullPath);
        $filename = preg_replace('/\.(json|html|md|txt)$/', '', $filename);

        if (!$ext) {
            $ext = pathinfo($fullPath, PATHINFO_EXTENSION) ?: 'json';
        }

        $this->setPath($dir);
        return $this->save($data, $filename, $ext);
    }

    public function saveJsonFromPath(array $data, string $fullPath): string
    {
        return $this->saveFromPath(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $fullPath,
            'json',
        );
    }

    /**
     * Save to a sibling path with a different extension.
     * e.g. saveSibling($html, '../data/mcp/page.json', 'html') → saves page.html
     */
    public function saveSibling(string $data, string $referencePath, string $ext): string
    {
        $dir = dirname($referencePath);
        $base = pathinfo($referencePath, PATHINFO_FILENAME);
        $this->setPath($dir);
        return $this->save($data, $base, $ext);
    }

    /**
     * Append to an existing file (for logs, accumulating results).
     */
    public function append(string $data, string $filename, string $ext = 'json'): string
    {
        $path = "{$this->path()}/{$filename}.{$ext}";
        file_put_contents($path, $data, FILE_APPEND);
        return $path;
    }
}
