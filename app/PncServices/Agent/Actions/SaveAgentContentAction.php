<?php

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
