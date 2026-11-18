<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Contracts;

interface WorkflowFactoryInterface
{
    /**
     * Inspects configuration handles and returns a dynamic data map.
     *
     * @return array{workflow: \App\PncAgentics\Contracts\WorkflowInterface, base_key: string}
     */
    public function make(string $type, bool $isUpdate = false): array;
}
