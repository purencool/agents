<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\PncServices\Contracts;

interface SMSInterface
{
    public function send(string $to, string $message): bool;
    public function sendBatch(array $recipients, string $message): array;
}
