<?php

namespace App\PncServices\Email;

use App\PncServices\Contracts\EmailServiceInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailService implements EmailServiceInterface
{
    public function send(string $to, string $subject, string $body, array $attachments = []): bool
    {
        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
            return true;
        } catch (\Exception $e) {
            Log::error("EmailService: Failed to send to {$to}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendTemplate(string $to, string $template, array $data): bool
    {
        try {
            Mail::send($template, $data, function ($message) use ($to) {
                $message->to($to);
            });
            return true;
        } catch (\Exception $e) {
            Log::error("EmailService: Failed to send template {$template}", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
