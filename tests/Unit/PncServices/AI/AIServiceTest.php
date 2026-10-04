<?php

namespace Tests\Unit\PncServices\AI;

use PHPUnit\Framework\TestCase;
use App\PncServices\AI\AIService;
use App\PncServices\Contracts\AIServiceInterface;

class AIServiceTest extends TestCase
{
    public function test_it_implements_interface(): void
    {
        $this->assertInstanceOf(AIServiceInterface::class, new AIService());
    }

    public function test_complete_returns_string(): void
    {
        $service  = new AIService();
        $response = $service->complete('Hello');
        $this->assertIsString($response);
    }

    public function test_embed_returns_array(): void
    {
        $service = new AIService();
        $result  = $service->embed('test text');
        $this->assertIsArray($result);
    }
}
