<?php

namespace Tests\Unit\PncAgentics\Skills;

use App\PncAgentics\Skills\Storage\PncSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use PHPUnit\Framework\TestCase;

class PncSkillStorageTest extends TestCase
{
    /** @test */
    public function toolkit_returns_skill_toolkit_instance(): void
    {
        $toolkit = PncSkillStorage::toolkit();

        $this->assertInstanceOf(SkillToolkit::class, $toolkit);
    }

    /** @test */
    public function repository_returns_skill_repository_instance(): void
    {
        $repo = PncSkillStorage::repository();

        $this->assertNotNull($repo);
    }

    /** @test */
    public function list_skills_returns_array_of_named_skills(): void
    {
        $skills = PncSkillStorage::listSkills();

        $this->assertIsArray($skills);
        $this->assertNotEmpty($skills);

        foreach ($skills as $skill) {
            $this->assertArrayHasKey('name', $skill);
            $this->assertArrayHasKey('description', $skill);
            $this->assertNotEmpty($skill['name']);
            $this->assertNotEmpty($skill['description']);
        }
    }

    /** @test */
    public function generic_skills_are_discovered(): void
    {
        $skills = PncSkillStorage::listSkills();
        $names = array_column($skills, 'name');

        // These should exist if resources/skills/ is populated
        $this->assertContains('error_handling', $names, 'Generic error-handling skill not found');
        $this->assertContains('logging', $names, 'Generic 500-logging skill not found');
        $this->assertContains('api_conventions', $names, 'Generic api-conventions skill not found');
        $this->assertContains('email_templates', $names, 'Generic email-templates skill not found');
    }

    /** @test */
    public function agent_specific_skills_are_discovered(): void
    {
        $skills = PncSkillStorage::listSkills();
        $names = array_column($skills, 'name');

        $this->assertContains('support', $names, 'Agent-specific support skill not found');
        $this->assertContains('billing', $names, 'Agent-specific billing skill not found');
    }
}
