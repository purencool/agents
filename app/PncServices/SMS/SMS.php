<?php

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
        $this->fromNumber = config('pncservices.sms.from_number');
        $this->sid        = config('pncservices.sms.sid');
        $this->token      = config('pncservices.sms.token');
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
