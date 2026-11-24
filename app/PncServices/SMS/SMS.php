<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncServices\SMS;

use App\PncServices\Contracts\SMSInterface;
use Illuminate\Support\Facades\Log;

class SMS implements SMSInterface
{
    protected string $fromNumber;
    protected string $sid;
    protected string $token;

    public function __construct()
    {
        $this->fromNumber = config("pnc.sms.from_number");
        $this->sid = config("pnc.sms.sid");
        $this->token = config("pnc.sms.token");
    }

    public function send(string $to, string $message): bool
    {
        // TODO: Implement Twilio / provider call
        Log::info("SMS: Sending to {$to}");
        return true;
    }

    public function sendBatch(array $recipients, string $message): array
    {
        $results = [];
        foreach ($recipients as $to) {
            $results[$to] = $this->send($to, $message);
        }
        return $results;
    }
}
