<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Contracts;

/**
 * Description
 */
interface AgentServiceInterface
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
