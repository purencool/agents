<?php

namespace App\PncServices\Contracts;

interface EmailServiceInterface
{
    public function send(string $to, string $subject, string $body, array $attachments = []): bool;
    public function sendTemplate(string $to, string $template, array $data): bool;
}
