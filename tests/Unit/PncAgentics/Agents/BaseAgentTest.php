<?php

namespace Tests\Unit\PncAgentics\Agents;

use PHPUnit\Framework\TestCase;
use App\PncAgentics\Agents\ExampleAgent;
use App\PncAgentics\Agents\BaseAgent;
use App\PncServices\Contracts\AIServiceInterface;
use App\PncServices\Contracts\RestServiceInterface;

class BaseAgentTest extends TestCase
{
    protected function makeAgent(): ExampleAgent
    {
        $ai   = $this->createMock(AIServiceInterface::class);
        $rest = $this->createMock(RestServiceInterface::class);
        return new ExampleAgent($ai, $rest);
    }

    public function test_it_extends_base_agent(): void
    {
        $agent = $this->makeAgent();
        $this->assertInstanceOf(BaseAgent::class, $agent);
    }

    public function test_name_returns_string(): void
    {
        $agent = $this->makeAgent();
        $this->assertIsString($agent->name());
    }

    public function test_run_returns_array(): void
    {
        $agent = $this->makeAgent();
        $result = $agent->run(['prompt' => 'Hello']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('agent', $result);
        $this->assertArrayHasKey('response', $result);
    }
}
