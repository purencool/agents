<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\PncServices\Contracts;

interface EmailServiceInterface
{
    public function send(
        string $to,
        string $subject,
        string $body,
        array $attachments = [],
    ): bool;
    public function sendTemplate(
        string $to,
        string $template,
        array $data,
    ): bool;
}
