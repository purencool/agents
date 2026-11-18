<?php

namespace App\PncServices\Email;

use App\PncServices\Contracts\EmailServiceInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * Sends emails via Laravel's Mail facade.
 *
 * Provides two sending modes:
 *  - Raw text emails (send)
 *  - Template-based emails with data interpolation (sendTemplate)
 *
 * All failures are caught, logged, and returned as `false`
 * rather than propagating exceptions to the caller.
 */
class EmailService implements EmailServiceInterface
{
    /**
     * Send a raw text email.
     *
     * @param string          $to          Recipient email address.
     * @param string          $subject     Email subject line.
     * @param string          $body        Plain-text email body.
     * @param array<string>   $attachments File paths to attach (unused in raw mode, reserved for interface compatibility).
     *
     * @return bool True if the email was sent successfully, false otherwise.
     */
    public function send(
        string $to,
        string $subject,
        string $body,
        array $attachments = [],
    ): bool {
        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
            return true;
        } catch (\Exception $e) {
            Log::error("EmailService: Failed to send to {$to}", [
                "error" => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send a template-based email with data interpolation.
     *
     * @param string          $to       Recipient email address.
     * @param string          $template Laravel mail view/template name.
     * @param array<string,mixed> $data Variables to interpolate into the template.
     *
     * @return bool True if the email was sent successfully, false otherwise.
     */
    public function sendTemplate(
        string $to,
        string $template,
        array $data,
    ): bool {
        try {
            Mail::send($template, $data, function ($message) use ($to) {
                $message->to($to);
            });
            return true;
        } catch (\Exception $e) {
            Log::error("EmailService: Failed to send template {$template}", [
                "error" => $e->getMessage(),
            ]);
            return false;
        }
    }
}
