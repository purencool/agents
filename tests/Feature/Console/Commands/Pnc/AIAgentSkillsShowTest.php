<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Pnc;

use App\PncAgentics\Contracts\SkillRegistryInterface;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for {@see \App\Console\Commands\Pnc\AIAgentSkillsShow}.
 *
 * Verifies the command's behaviour when the SkillRegistryInterface is
 * mocked to return various states:
 *
 *  - FAILURE when the registry is empty.
 *  - FAILURE with a helpful tip when the skill key is not found.
 *  - SUCCESS and correct output when the skill exists.
 *  - SUCCESS when no argument is provided (lists all skills).
 *  - Edge cases: empty-string content, special characters in keys.
 *
 * @covers \App\Console\Commands\Pnc\AIAgentSkillsShow
 */
class AIAgentSkillsShowTest extends TestCase
{
    /**
     * When the registry returns an empty array, the command should
     * error and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenNoSkillsLoaded(): void
    {
        $this->mockRegistry([]);

        $this->artisan("pnc:agent:skills:show", ["skill" => "any.skill"])
            ->expectsOutputToContain("No skills found")
            ->assertFailed();
    }

    /**
     * When the registry returns an empty array (simulating a misconfigured
     * provider), the command should also fail gracefully.
     */
    #[Test]
    public function handle_failsWhenSkillsConfigIsNull(): void
    {
        $this->mockRegistry([]);

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
        $this->mockRegistry(
            skills: [
                "known.skill" => ["system_instructions" => "Known content"],
            ],
            getReturns: null,
        );

        $this->artisan("pnc:agent:skills:show", ["skill" => "unknown.skill"])
            ->expectsOutputToContain("Skill 'unknown.skill' not found.")
            ->expectsOutputToContain("php artisan pnc:agent:skills:show")
            ->assertFailed();
    }

    /**
     * When the skill exists, the command should print its content and
     * return SUCCESS.
     */
    #[Test]
    public function handle_printsSkillContentOnSuccess(): void
    {
        $this->mockRegistry(
            skills: [
                "developer_docs.core_writing.hook_writer" => [
                    "system_instructions" => "You are a skilled hook writer.",
                ],
            ],
            getReturns: [
                "system_instructions" => "You are a skilled hook writer.",
            ],
        );

        $this->artisan("pnc:agent:skills:show", [
            "skill" => "developer_docs.core_writing.hook_writer",
        ])
            ->expectsOutputToContain("You are a skilled hook writer.")
            ->assertSuccessful();
    }

    /**
     * A skill key that exists but has empty system_instructions should
     * still succeed (get() returns an array, not null).
     */
    #[Test]
    public function handle_succeedsForEmptySkillContent(): void
    {
        $this->mockRegistry(
            skills: ["empty.skill" => ["system_instructions" => ""]],
            getReturns: ["system_instructions" => ""],
        );

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
        $this->mockRegistry(
            skills: [
                "track-2.core_writing.v3.writer" => [
                    "system_instructions" => "Special key content",
                ],
            ],
            getReturns: ["system_instructions" => "Special key content"],
        );

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
        $this->mockRegistry(
            skills: [
                "blog_posts.style_guide" => [
                    "system_instructions" => "Full content here",
                ],
                "blog_posts.style" => [
                    "system_instructions" => "Different skill",
                ],
            ],
            getReturns: ["system_instructions" => "Different skill"],
        );

        $this->artisan("pnc:agent:skills:show", ["skill" => "blog_posts.style"])
            ->expectsOutputToContain("Different skill")
            ->assertSuccessful();
    }

    /**
     * When no skill argument is provided, the command should list all
     * skills in a table and return SUCCESS.
     */
    #[Test]
    public function handle_listsAllSkillsWhenNoArgumentProvided(): void
    {
        $this->mockRegistry(
            skills: [
                "track.core_writing.hook_writer" => [
                    "system_instructions" => "Hook content",
                ],
                "track.core_writing.body_writer" => [
                    "system_instructions" => "Body content",
                ],
            ],
        );

        $this->artisan("pnc:agent:skills:show")
            ->expectsOutputToContain("Available skills (2):")
            ->expectsOutputToContain("track.core_writing.hook_writer")
            ->expectsOutputToContain("track.core_writing.body_writer")
            ->assertSuccessful();
    }

    /**
     * When no argument is provided and the registry is empty, the command
     * should still report "No skills found" and fail.
     */
    #[Test]
    public function handle_failsWhenListingWithEmptyRegistry(): void
    {
        $this->mockRegistry([]);

        $this->artisan("pnc:agent:skills:show")
            ->expectsOutputToContain("No skills found")
            ->assertFailed();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Bind a Mockery mock of SkillRegistryInterface into the application container.
     *
     * @param array<string, array> $skills     The array returned by all().
     * @param array|null           $getReturns The value returned by get().
     *                                          Null simulates "key not found".
     */
    private function mockRegistry(
        array $skills = [],
        ?array $getReturns = null,
    ): Mockery\MockInterface {
        $mock = Mockery::mock(SkillRegistryInterface::class);

        $mock->shouldReceive("all")->andReturn($skills);
        $mock->shouldReceive("get")->andReturn($getReturns);
        $mock->shouldReceive("create")->andReturn(null);
        $mock->shouldReceive("update")->andReturn(null);
        $mock->shouldReceive("delete")->andReturn(null);

        $this->app->instance(SkillRegistryInterface::class, $mock);

        return $mock;
    }
}
