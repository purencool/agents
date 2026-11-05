<?php

namespace App\PncAgentics\Contracts;

interface FileSaverInterface
{
    public function setPath(string $path): void;
    public function path(): string;
    public function save(string $data, string $filename, string $ext = 'json'): string;
    public function saveJson(array $data, string $filename): string;
    public function saveHtml(string $html, string $filename): string;
    public function saveMarkdown(string $content, string $filename): string;
    public function saveFromPath(string $data, string $fullPath, ?string $ext = null): string;
    public function saveJsonFromPath(array $data, string $fullPath): string;
    public function saveSibling(string $data, string $referencePath, string $ext): string;
    public function append(string $data, string $filename, string $ext = 'json'): string;
}
