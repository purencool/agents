<?php

namespace Tests\Feature\PncAgentics;

use App\PncAgentics\Skills\Storage\PncSkillStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillToolkitIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function skill_toolkit_resolves_from_container(): void
    {
        $repo = app('pnc.skills');

        $this->assertNotNull($repo);
    }

    /** @test */
    public function all_shipped_skills_are_loadable(): void
    {
        $skills = PncSkillStorage::listSkills();

        $this->assertNotEmpty($skills, 'No skills discovered — check storage paths');

        foreach ($skills as $skill) {
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9\-]+$/',
                $skill['name'],
                "Skill '{$skill['name']}' has invalid name format"
            );
            $this->assertNotEmpty($skill['description'], "Skill '{$skill['name']}' has empty description");
        }
    }

    /** @test */
    public function skill_md_files_are_well_formed(): void
    {
        $dirs = [
            resource_path('skills'),
            app_path('PncAgentics/Skills'),
        ];

        foreach ($dirs as $baseDir) {
            if (! is_dir($baseDir)) continue;

            $files = glob($baseDir . '/*/SKILL.md');
            foreach ($files as $file) {
                $content = file_get_contents($file);

                // Must start with frontmatter
                $this->assertStringStartsWith("---", $content, "Missing frontmatter in: $file");

                // Must have name and description
                $this->assertStringContainsString('name:', $content, "Missing name in: $file");
                $this->assertStringContainsString('description:', $content, "Missing description in: $file");

                // Must have a markdown heading
                $this->assertMatchesRegularExpression('/^# .+$/m', $content, "Missing H1 heading in: $file");
            }
        }
    }
}
