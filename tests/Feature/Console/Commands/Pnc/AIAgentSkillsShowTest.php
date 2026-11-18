<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Pnc;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for {@see \App\Console\Commands\Pnc\AIAgentSkillsShow}.
 *
 * Verifies:
 *  - FAILURE when the skills registry is empty or null.
 *  - FAILURE with a helpful tip when the skill key is not found.
 *  - SUCCESS and correct output when the skill exists.
 *  - Edge cases: empty-string content, special characters in keys.
 */
class AIAgentSkillsShowTest extends TestCase
{
    /**
     * When config('pnc.skills') is an empty array, the command should
     * error and return FAILURE.
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
     * When config('pnc.skills') is null, the command should also fail
     * gracefully (empty() catches null).
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
            "known.skill" => "Known content",
        ]);

        $this->artisan("pnc:agent:skills:show", ["skill" => "unknown.skill"])
            ->expectsOutputToContain("Skill 'unknown.skill' not found.")
            ->expectsOutputToContain("Tip: Use 'php artisan agent:skills'")
            ->assertFailed();
    }

    /**
     * When the skill exists, the command should print its content and
     * return SUCCESS.
     */
    #[Test]
    public function handle_printsSkillContentOnSuccess(): void
    {
        config()->set("pnc.skills", [
            "developer_docs.core_writing.hook_writer" =>
                "You are a skilled hook writer.",
        ]);

        $this->artisan("pnc:agent:skills:show", [
            "skill" => "developer_docs.core_writing.hook_writer",
        ])
            ->expectsOutputToContain("You are a skilled hook writer.")
            ->assertSuccessful();
    }

    /**
     * A skill key that exists but maps to an empty string should still
     * succeed (array_key_exists passes, empty() is not used for lookup).
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

    /**
     * A skill key with special characters (hyphens, numbers) should
     * resolve correctly.
     */
    #[Test]
    public function handle_resolvesKeysWithSpecialCharacters(): void
    {
        config()->set("pnc.skills", [
            "track-2.core_writing.v3.writer" => "Special key content",
        ]);

        $this->artisan("pnc:agent:skills:show", [
            "skill" => "track-2.core_writing.v3.writer",
        ])
            ->expectsOutputToContain("Special key content")
            ->assertSuccessful();
    }

    /**
     * The "not found" error should not be triggered when the key exists
     * but the registry also contains other keys (no prefix matching).
     */
    #[Test]
    public function handle_requiresExactKeyMatch(): void
    {
        config()->set("pnc.skills", [
            "blog_posts.style_guide" => "Full content here",
            "blog_posts.style" => "Different skill",
        ]);

        // "blog_posts.style" exists, so this should succeed.
        $this->artisan("pnc:agent:skills:show", ["skill" => "blog_posts.style"])
            ->expectsOutputToContain("Different skill")
            ->assertSuccessful();
    }
}
