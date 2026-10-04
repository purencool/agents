<?php

namespace Tests\Unit\PncServices\Email;

use PHPUnit\Framework\TestCase;
use App\PncServices\Email\EmailService;
use App\PncServices\Contracts\EmailServiceInterface;

class EmailServiceTest extends TestCase
{
    public function test_it_implements_interface(): void
    {
        $this->assertInstanceOf(EmailServiceInterface::class, new EmailService());
    }

    public function test_send_returns_bool(): void
    {
        $service = new EmailService();
        $result  = $service->send('test@example.com', 'Subject', 'Body');
        $this->assertIsBool($result);
    }
}
