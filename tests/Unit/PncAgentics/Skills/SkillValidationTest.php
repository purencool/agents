<?php

namespace Tests\Unit\PncAgentics\Skills;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\TestCase;

class SkillValidationTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = base_path('tests/Fixtures/skills');
    }

    /** @test */
    public function valid_skill_has_correct_frontmatter(): void
    {
        $content = File::get($this->fixturesDir . '/generic/test_skill/SKILL.md');

        $this->assertStringStartsWith("---", $content);
        $this->assertStringContainsString("name: test_skill", $content);
        $this->assertStringContainsString("description:", $content);
    }

    /** @test */
    public function skill_name_matches_folder_name(): void
    {
        $folder = 'test-skill';
        $content = File::get($this->fixturesDir . "/generic/$folder/SKILL.md");

        preg_match('/^name:\s*(.+)$/m', $content, $matches);
        $this->assertNotEmpty($matches, 'No name field found in frontmatter');
        $this->assertSame($folder, trim($matches[1]));
    }

    /** @test */
    public function skill_name_uses_only_lowercase_hyphens_and_numbers(): void
    {
        $validDirs = File::directories($this->fixturesDir . '/generic');

        foreach ($validDirs as $dir) {
            $name = basename($dir);
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9\-]+$/',
                $name,
                "Skill folder '$name' violates naming convention (lowercase, numbers, hyphens only)"
            );
        }
    }

    /** @test */
    public function invalid_skill_without_frontmatter_is_detected(): void
    {
        $content = File::get($this->fixturesDir . '/invalid/no_frontmatter/SKILL.md');

        $this->assertStringNotStartsWith("---", $content);
    }

    /** @test */
    public function invalid_skill_with_mismatched_name_is_detected(): void
    {
        $folder = 'wrong_name';
        $content = File::get($this->fixturesDir . "/invalid/$folder/SKILL.md");

        preg_match('/^name:\s*(.+)$/m', $content, $matches);
        $this->assertNotEmpty($matches);
        $this->assertNotSame($folder, trim($matches[1]));
    }

    /** @test */
    public function invalid_skill_with_uppercase_name_is_detected(): void
    {
        $folder = 'bad_name';
        $content = File::get($this->fixturesDir . "/invalid/$folder/SKILL.md");

        preg_match('/^name:\s*(.+)$/m', $content, $matches);
        $this->assertNotEmpty($matches);
        $this->assertNotSame($folder, trim($matches[1]));
    }

    /** @test */
    public function description_is_not_empty_for_valid_skills(): void
    {
        $validDirs = array_merge(
            File::directories($this->fixturesDir . '/generic'),
            File::directories($this->fixturesDir . '/agents')
        );

        foreach ($validDirs as $dir) {
            $content = File::get($dir . '/SKILL.md');
            preg_match('/^description:\s*(.+)$/m', $content, $matches);
            $this->assertNotEmpty($matches, "Skill in " . basename($dir) . " has no description");
            $this->assertNotEmpty(trim($matches[1]));
        }
    }
}
