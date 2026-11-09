<?php

namespace Tests\Unit\PncServices\SMS;

use App\PncServices\SMS\SMS;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SMSTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            "pncservices.sms.from_number" => "+1234567890",
            "pncservices.sms.sid" => "test_sid",
            "pncservices.sms.token" => "test_token",
        ]);
    }

    #[Test]
    public function it_sends_a_single_sms_and_returns_true(): void
    {
        Log::shouldReceive("info")
            ->once()
            ->with("SMS: Sending to +61400000001");

        $sms = new SMS();
        $result = $sms->send("+61400000001", "Hello");

        $this->assertTrue($result);
    }

    #[Test]
    public function it_sends_batch_and_returns_results_for_each_recipient(): void
    {
        $recipients = ["+61400000001", "+61400000002", "+61400000003"];

        Log::shouldReceive("info")
            ->once()
            ->with("SMS: Sending to +61400000001");
        Log::shouldReceive("info")
            ->once()
            ->with("SMS: Sending to +61400000002");
        Log::shouldReceive("info")
            ->once()
            ->with("SMS: Sending to +61400000003");

        $sms = new SMS();
        $results = $sms->sendBatch($recipients, "Batch message");

        $this->assertSame(
            [
                "+61400000001" => true,
                "+61400000002" => true,
                "+61400000003" => true,
            ],
            $results,
        );
    }

    #[Test]
    public function it_returns_empty_array_for_empty_batch(): void
    {
        $sms = new SMS();
        $results = $sms->sendBatch([], "No recipients");

        $this->assertSame([], $results);
    }
}
