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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the {@see \App\Console\Commands\Pnc\AIAgentSkillsUpdate} command.
 *
 * Verifies:
 *  - FAILURE when no --input option is provided.
 *  - FAILURE when the JSON is malformed.
 *  - FAILURE when required keys ("skill", "content") are missing.
 *  - SUCCESS when creating a new skill (key does not exist).
 *  - SUCCESS when updating an existing skill (key already exists).
 *  - FAILURE when the registry throws an exception (disk error, conflict).
 *
 * @covers \App\Console\Commands\Pnc\AIAgentSkillsUpdate
 */
class AIAgentSkillsUpdateTest extends TestCase
{
    /**
     * When no --input option is provided, the command should error
     * and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenNoInputProvided(): void
    {
        $this->mockRegistry();

        $this->artisan("pnc:agent:skills:update")
            ->expectsOutputToContain("No input provided")
            ->assertFailed();
    }

    /**
     * When the --input value is not valid JSON, the command should
     * report the JSON error and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenJsonIsMalformed(): void
    {
        $this->mockRegistry();

        $this->artisan("pnc:agent:skills:update", [
            "--input" => "{invalid json",
        ])
            ->expectsOutputToContain("Invalid JSON")
            ->assertFailed();
    }

    /**
     * When the JSON is valid but missing the "skill" key, the command
     * should error and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenSkillKeyMissing(): void
    {
        $this->mockRegistry();

        $this->artisan("pnc:agent:skills:update", [
            "--input" => json_encode(["content" => "Some content"]),
        ])
            ->expectsOutputToContain('Missing "skill" key')
            ->assertFailed();
    }

    /**
     * When the JSON is valid but missing the "content" key, the command
     * should error and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenContentKeyMissing(): void
    {
        $this->mockRegistry();

        $this->artisan("pnc:agent:skills:update", [
            "--input" => json_encode(["skill" => "test.skill"]),
        ])
            ->expectsOutputToContain('Missing "content" key')
            ->assertFailed();
    }

    /**
     * When the skill key does not exist in the registry, the command
     * should call create() and return SUCCESS.
     */
    #[Test]
    public function handle_createsNewSkillWhenKeyDoesNotExist(): void
    {
        $this->mockRegistry(
            getReturns: null, // key not found → create path
        );

        $this->artisan("pnc:agent:skills:update", [
            "--input" => json_encode([
                "skill" => "new.skill.key",
                "content" => "New skill content",
            ]),
        ])
            ->expectsOutputToContain(
                "Successfully created skill: new.skill.key",
            )
            ->assertSuccessful();
    }

    /**
     * When the skill key already exists in the registry, the command
     * should call update() and return SUCCESS.
     */
    #[Test]
    public function handle_updatesExistingSkillWhenKeyExists(): void
    {
        $this->mockRegistry(
            getReturns: "Existing content", // key found → update path
        );

        $this->artisan("pnc:agent:skills:update", [
            "--input" => json_encode([
                "skill" => "existing.skill.key",
                "content" => "Updated content",
            ]),
        ])
            ->expectsOutputToContain(
                "Successfully updated skill: existing.skill.key",
            )
            ->assertSuccessful();
    }

    /**
     * When the registry throws an InvalidArgumentException (e.g., race
     * condition where key was created between get() and create()), the
     * command should report the error and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenRegistryThrowsInvalidArgument(): void
    {
        $mock = $this->mock(SkillRegistryInterface::class);
        $mock->method("get")->willReturn(null);
        $mock
            ->method("create")
            ->willThrowException(
                new \InvalidArgumentException("Skill 'x.y' already exists."),
            );
        $this->app->instance(SkillRegistryInterface::class, $mock);

        $this->artisan("pnc:agent:skills:update", [
            "--input" => json_encode([
                "skill" => "x.y",
                "content" => "Content",
            ]),
        ])
            ->expectsOutputToContain("already exists")
            ->assertFailed();
    }

    /**
     * When the registry throws a RuntimeException (e.g., disk write failure),
     * the command should report the error and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenRegistryThrowsRuntimeException(): void
    {
        $mock = $this->mock(SkillRegistryInterface::class);
        $mock->method("get")->willReturn(null);
        $mock
            ->method("create")
            ->willThrowException(new \RuntimeException("Permission denied"));
        $this->app->instance(SkillRegistryInterface::class, $mock);

        $this->artisan("pnc:agent:skills:update", [
            "--input" => json_encode([
                "skill" => "x.y",
                "content" => "Content",
            ]),
        ])
            ->expectsOutputToContain("Failed to write skill")
            ->assertFailed();
    }

    /**
     * The "content" value of an empty string is valid (it is not null),
     * so the command should proceed and succeed.
     */
    #[Test]
    public function handle_succeedsForEmptyContentString(): void
    {
        $this->mockRegistry(getReturns: null);

        $this->artisan("pnc:agent:skills:update", [
            "--input" => json_encode([
                "skill" => "empty.content",
                "content" => "",
            ]),
        ])
            ->expectsOutputToContain(
                "Successfully created skill: empty.content",
            )
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Bind a mock SkillRegistryInterface into the application container.
     *
     * @param string|null $getReturns The value returned by get().
     *                                Null simulates "key not found".
     */
    private function mockRegistry(
        ?string $getReturns = null,
    ): \PHPUnit\Framework\MockObject\MockObject {
        $mock = $this->mock(SkillRegistryInterface::class);

        $mock->method("all")->willReturn([]);
        $mock->method("get")->willReturn($getReturns);
        $mock->method("create")->willReturn(null);
        $mock->method("update")->willReturn(null);
        $mock->method("delete")->willReturn(null);

        $this->app->instance(SkillRegistryInterface::class, $mock);

        return $mock;
    }
}
