<?php

namespace Tests\Unit\PncServices\SMS;

use Tests\TestCase;
use App\PncServices\SMS\SMS;
use App\PncServices\Contracts\SMSInterface;

class SMSInterfaceTest extends TestCase
{
    public function test_it_implements_interface(): void
    {
        $this->assertInstanceOf(SMSInterface::class, new SMS());
    }

    public function test_send_returns_bool(): void
    {
        $service = new SMS();
        $result  = $service->send('+61400000000', 'Test message');
        $this->assertIsBool($result);
    }

    public function test_send_batch_returns_array(): void
    {
        $service = new SMS();
        $result  = $service->sendBatch(['+61400000001', '+61400000002'], 'Batch');
        $this->assertIsArray($result);
    }
}   