<?php

namespace Tests\Unit\PncAgentics\Agents;

use App\PncAgentics\Agents\BaseAgent;
use PHPUnit\Framework\TestCase;

class BaseAgentTest extends TestCase
{
    /** @test */
    public function base_agent_can_be_instantiated(): void
    {
        $agent = new BaseAgent();

        $this->assertInstanceOf(BaseAgent::class, $agent);
    }

    /** @test */
    public function base_agent_has_skill_toolkit_configured(): void
    {
        $agent = new BaseAgent();

        // Verify the agent has tools registered (SkillToolkit)
        $tools = $agent->tools();

        $this->assertNotEmpty($tools, 'BaseAgent should have at least one tool (SkillToolkit)');
    }
}
