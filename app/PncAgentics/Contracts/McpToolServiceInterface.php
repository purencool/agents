<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Contracts;

interface McpToolServiceInterface
{
    public function call(string $tool, string $input, ?string $arg2 = null, ?string $arg3 = null): array;
    public function callWithArtifacts(string $tool, string $input, string $researchPath, string $filename = 'output', ?string $driver = null): array;
    public function callSequence(array $calls, ?string $driver = null): array;
    public function validateAll(): array;
    public function availableTools(): array;
    public function isAvailable(): bool;
}
