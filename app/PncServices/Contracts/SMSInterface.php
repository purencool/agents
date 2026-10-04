<?php

namespace App\PncServices\Contracts;

interface SMSInterface
{
    public function send(string $to, string $message): bool;
    public function sendBatch(array $recipients, string $message): array;
}
