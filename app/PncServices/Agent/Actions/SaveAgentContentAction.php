<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\PncServices\Agent\Actions;

class SaveAgentContentAction
{
    public function __construct() {}

    /**
     * @param array $structure
     */
    public function execute(array $structure): array
    {
        $results = $structure;
        return $results;
    }
}
