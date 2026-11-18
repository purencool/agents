<?php

declare(strict_types=1);

namespace App\PncServices\Contracts;

/**
 * Description
 */
interface AIServiceInterface
{
    /**
     * @param array<mixed> $options
     */
    public function complete(string $prompt, array $options = []): string;

    /**
     * @return array<mixed>
     */
    public function embed(string $text): array;

    /**
     * @param array<mixed> $history
     */
    public function chat(string $message, array $history = []): string;
}
