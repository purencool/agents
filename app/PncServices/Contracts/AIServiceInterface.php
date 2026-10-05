<?php

namespace App\PncServices\Contracts;


interface AIServiceInterface
{
    public function complete(string $prompt, array $options = []): string;
    public function embed(string $text): array;
    public function chat(string $message, array $history = []): string;
}
