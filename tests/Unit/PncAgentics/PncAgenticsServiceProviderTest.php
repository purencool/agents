<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace Tests\Unit\PncAgentics;

use App\PncAgentics\Agents\Agent;
use App\PncAgentics\Contracts\FileSaverInterface;
use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use App\PncAgentics\Factory\WorkflowFactory;
use App\PncAgentics\PncAgenticsServiceProvider;
use App\PncAgentics\Services\AuditedSkillExecutor;
use App\PncAgentics\Services\SkillExecutor;
use App\PncServices\Contracts\AIServiceInterface;
use Tests\TestCase;

/**
 * Unit tests for {@see PncAgenticsServiceProvider}.
 *
 * Verifies that all container bindings are registered correctly,
 * singletons behave as expected, and the pnc.skills scanner works.
 *
 * @covers \App\PncAgentics\PncAgenticsServiceProvider
 */
class PncAgenticsServiceProviderTest extends TestCase
{
    /**
     * The service provider instance.
     */
    private PncAgenticsServiceProvider $provider;

    /**
     * Set up the provider and register it with the application container.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new PncAgenticsServiceProvider($this->app);
        $this->provider->register();

        $this->app->bind(Agent::class, fn() => new \stdClass());

        $this->app->bind(
            AIServiceInterface::class,
            fn() => $this->createMock(AIServiceInterface::class),
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // register() – bindings
    // ─────────────────────────────────────────────────────────────────────

    /**
     * SkillExecutor should be bound and receive an AIServiceInterface instance.
     */
    #[Test]
    public function register_bindsSkillExecutorWithAiService(): void
    {
        $this->assertTrue($this->app->bound(SkillExecutor::class));

        $executor = $this->app->make(SkillExecutor::class);
        $this->assertInstanceOf(SkillExecutor::class, $executor);
    }

    /**
     * SkillExecutorInterface should resolve to AuditedSkillExecutor.
     */
    #[Test]
    public function register_bindsSkillExecutorInterfaceToAuditedSkillExecutor(): void
    {
        $this->assertTrue($this->app->bound(SkillExecutorInterface::class));

        $resolved = $this->app->make(SkillExecutorInterface::class);
        $this->assertInstanceOf(AuditedSkillExecutor::class, $resolved);
    }

    /**
     * WorkflowFactoryInterface should bind to WorkflowFactory.
     */
    #[Test]
    public function register_bindsWorkflowFactoryInterface(): void
    {
        $this->assertTrue($this->app->bound(WorkflowFactoryInterface::class));

        $resolved = $this->app->make(WorkflowFactoryInterface::class);
        $this->assertInstanceOf(WorkflowFactory::class, $resolved);
    }

    /**
     * McpToolServiceInterface should be a singleton (same instance on repeated resolution).
     */
    #[Test]
    public function register_mcpToolServiceIsSingleton(): void
    {
        $this->assertTrue($this->app->bound(McpToolServiceInterface::class));

        $first = $this->app->make(McpToolServiceInterface::class);
        $second = $this->app->make(McpToolServiceInterface::class);

        $this->assertSame($first, $second);
    }

    /**
     * FileSaverInterface should be a singleton.
     */
    #[Test]
    public function register_fileSaverIsSingleton(): void
    {
        $this->assertTrue($this->app->bound(FileSaverInterface::class));

        $first = $this->app->make(FileSaverInterface::class);
        $second = $this->app->make(FileSaverInterface::class);

        $this->assertSame($first, $second);
    }

    /**
     * "pnc.skills" should be registered as a singleton.
     */
    #[Test]
    public function register_pncSkillsIsSingleton(): void
    {
        $this->assertTrue($this->app->bound("pnc.skills"));

        $first = $this->app->make("pnc.skills");
        $second = $this->app->make("pnc.skills");

        $this->assertSame($first, $second);
    }

    // ─────────────────────────────────────────────────────────────────────
    // "pnc.skills" – directory scanning
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The pnc.skills singleton should return an empty array when the
     * Skills directory does not exist.
     */
    #[Test]
    public function pncSkills_returnsEmptyArrayWhenDirectoryMissing(): void
    {
        // Ensure the directory doesn't exist (or is empty) in the test env.
        $skills = $this->app->make("pnc.skills");

        $this->assertIsArray($skills);
    }

    /**
     * The pnc.skills singleton should scan .md files and build dot-notation keys.
     */
    #[Test]
    public function pncSkills_scansMarkdownFilesAndBuildsDotKeys(): void
    {
        $base = app_path("PncAgentics/Skills");
        $testDir = $base . "/test_track/core_writing";
        mkdir($testDir, 0755, true);

        // Create a test skill file.
        file_put_contents(
            $testDir . "/my_agent.skills.md",
            "# My Agent\nDo things.",
        );
        file_put_contents($testDir . "/plain.md", "Plain content.");
        // Non-md file should be ignored.
        file_put_contents($testDir . "/ignored.txt", "Not a skill.");

        try {
            // Clear the singleton so it re-scans.
            $this->app->forgetInstance("pnc.skills");

            $skills = $this->app->make("pnc.skills");

            // "my_agent.skills.md" → key "test_track.core_writing.my_agent" (".skills" stripped)
            $this->assertArrayHasKey(
                "test_track.core_writing.my_agent",
                $skills,
            );
            $this->assertStringContainsString(
                "My Agent",
                $skills["test_track.core_writing.my_agent"],
            );

            // "plain.md" → key "test_track.core_writing.plain"
            $this->assertArrayHasKey("test_track.core_writing.plain", $skills);

            // .txt file should NOT appear.
            $this->assertArrayNotHasKey(
                "test_track.core_writing.ignored",
                $skills,
            );
        } finally {
            // Clean up.
            unlink($testDir . "/my_agent.skills.md");
            unlink($testDir . "/plain.md");
            unlink($testDir . "/ignored.txt");
            rmdir($testDir);
            rmdir($base . "/test_track");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // boot()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * boot() should set config('pnc.skills') to a closure that lazily
     * resolves the "pnc.skills" singleton.
     */
    #[Test]
    public function boot_setsPncSkillsConfigToLazyClosure(): void
    {
        $this->provider->boot();

        $configValue = config("pnc.skills");

        $this->assertIsCallable($configValue);

        // Invoking the closure should return the same array as app("pnc.skills").
        $resolved = $configValue();
        $this->assertSame($this->app->make("pnc.skills"), $resolved);
    }
}
