<?php

namespace Tests\Feature\PncAgentics\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MakeSkillCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_a_generic_skill(): void
    {
        $targetDir = resource_path('skills/test-command-skill');

        File::deleteDirectory($targetDir);

        $this->artisan('pnc:make-skill', ['name' => 'test-command-skill', '--generic' => true])
            ->expectsOutputToContain('Skill created')
            ->assertSuccessful();

        $this->assertDirectoryExists($targetDir);
        $this->assertFileExists($targetDir . '/SKILL.md');

        $content = File::get($targetDir . '/SKILL.md');
        $this->assertStringContainsString('name: test-command-skill', $content);
        $this->assertStringContainsString('description: TODO', $content);

        // Cleanup
        File::deleteDirectory($targetDir);
    }

    /** @test */
    public function it_creates_an_agent_specific_skill(): void
    {
        $targetDir = app_path('PncAgentics/Skills/agents-testagent/test-agent-skill');

        File::deleteDirectory($targetDir);

        $this->artisan('pnc:make-skill', [
            'name' => 'test-agent-skill',
            '--agent' => 'testagent',
        ])
            ->expectsOutputToContain('Skill created')
            ->assertSuccessful();

        $this->assertDirectoryExists($targetDir);
        $this->assertFileExists($targetDir . '/SKILL.md');

        // Cleanup
        File::deleteDirectory($targetDir);
    }

    /** @test */
    public function it_rejects_invalid_skill_names(): void
    {
        $this->artisan('pnc:make-skill', ['name' => 'Invalid_Name', '--generic' => true])
            ->expectsOutputToContain('lowercase letters, numbers, and hyphens only')
            ->assertFailed();
    }

    /** @test */
    public function it_rejects_duplicate_skill_names(): void
    {
        $targetDir = resource_path('skills/duplicate-test');

        // Create it first
        mkdir($targetDir, 0755, true);
        file_put_contents($targetDir . '/SKILL.md', "---\nname: duplicate-test\ndescription: x\n---\n\n# Dup\n");

        $this->artisan('pnc:make-skill', ['name' => 'duplicate-test', '--generic' => true])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        // Cleanup
        File::deleteDirectory($targetDir);
    }

    /** @test */
    public function it_converts_spaces_to_hyphens(): void
    {
        $targetDir = resource_path('skills/my-new-skill');

        File::deleteDirectory($targetDir);

        $this->artisan('pnc:make-skill', ['name' => 'my new skill', '--generic' => true])
            ->expectsOutputToContain('Skill created')
            ->assertSuccessful();

        $this->assertDirectoryExists($targetDir);

        // Cleanup
        File::deleteDirectory($targetDir);
    }
}
