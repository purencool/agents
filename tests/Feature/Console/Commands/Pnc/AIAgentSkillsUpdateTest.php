<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Pnc;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the {@see \App\Console\Commands\Pnc\AIAgentSkillsShow} command.
 *
 * Verifies:
 *  - FAILURE when the skills config is empty.
 *  - FAILURE with a helpful tip when the skill key is not found.
 *  - SUCCESS and correct output when the skill exists.
 */
class AIAgentSkillsUpdateTest extends TestCase
{
    /**
     * When config('pnc.skills') is empty, the command should error
     * and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenNoSkillsLoaded(): void
    {
        config()->set("pnc.skills", []);

        $this->artisan("pnc:agent:skills:show", ["skill" => "any.skill"])
            ->expectsOutputToContain("No skills found")
            ->assertFailed();
    }

    /**
     * When config('pnc.skills') is null (not set), the command should
     * also fail gracefully.
     */
    #[Test]
    public function handle_failsWhenSkillsConfigIsNull(): void
    {
        config()->set("pnc.skills", null);

        $this->artisan("pnc:agent:skills:show", ["skill" => "any.skill"])
            ->expectsOutputToContain("No skills found")
            ->assertFailed();
    }

    /**
     * When the requested skill key does not exist in the registry, the
     * command should error, show a tip, and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenSkillNotFound(): void
    {
        config()->set("pnc.skills", [
            "known.skill" => "# Known Skill",
        ]);

        $this->artisan("pnc:agent:skills:show", ["skill" => "unknown.skill"])
            ->expectsOutputToContain("Skill 'unknown.skill' not found.")
            ->expectsOutputToContain("Tip: Use 'php artisan agent:skills'")
            ->assertFailed();
    }

    /**
     * When the skill exists, the command should print its markdown
     * content and return SUCCESS.
     */
    #[Test]
    public function handle_printsSkillContentOnSuccess(): void
    {
        config()->set("pnc.skills", [
            "developer_docs.core_writing.hook_introduction_writer" =>
                "You are a skilled writer who crafts compelling hooks.",
        ]);

        $this->artisan("pnc:agent:skills:show", [
            "skill" => "developer_docs.core_writing.hook_introduction_writer",
        ])
            ->expectsOutputToContain("crafts compelling hooks")
            ->assertSuccessful();
    }

    /**
     * The full markdown content (including newlines) should be printed
     * as-is without modification.
     */
    #[Test]
    public function handle_printsFullContentUnmodified(): void
    {
        config()->set("pnc.skills", [
            "test.skill" => "Item one and Item two with a blockquote",
        ]);

        $this->artisan("pnc:agent:skills:show", ["skill" => "test.skill"])
            ->expectsOutputToContain("Item one and Item two with a blockquote")
            ->assertSuccessful();
    }

    /**
     * A skill key that exists but maps to an empty string should still
     * succeed (it is a valid entry, just empty).
     */
    #[Test]
    public function handle_succeedsForEmptySkillContent(): void
    {
        config()->set("pnc.skills", [
            "empty.skill" => "",
        ]);

        $this->artisan("pnc:agent:skills:show", [
            "skill" => "empty.skill",
        ])->assertSuccessful();
    }
}
