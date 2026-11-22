<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see AppServiceProvider}.
 *
 * Verifies that boot() creates directories for the following env vars
 * when they are set and the directory does not exist:
 *  - AGENT_DATA_STORE
 *  - OVERLAY_WORKFLOWS
 *  - OVERLAY_OVERRIDES
 *
 * Also verifies that no action is taken when:
 *  - The directory already exists.
 *  - The env var is not set.
 *
 * @covers \App\Providers\AppServiceProvider
 */
class AppServiceProviderTest extends TestCase
{
    private string $dataStorePath;
    private string $dataStoreResolved;
    private string $workflowOverlayPath;
    private string $workflowOverlayResolved;
    private string $skillOverlayPath;
    private string $skillOverlayResolved;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataStorePath = "storage/test_pnc_data_" . uniqid();
        $this->dataStoreResolved = base_path($this->dataStorePath);

        $this->workflowOverlayPath = "storage/test_pnc_wf_" . uniqid();
        $this->workflowOverlayResolved = base_path($this->workflowOverlayPath);

        $this->skillOverlayPath = "storage/test_pnc_skill_" . uniqid();
        $this->skillOverlayResolved = base_path($this->skillOverlayPath);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dataStoreResolved);
        $this->removeDir($this->workflowOverlayResolved);
        $this->removeDir($this->skillOverlayResolved);

        $this->unsetEnv("AGENT_DATA_STORE");
        $this->unsetEnv("OVERLAY_WORKFLOWS");
        $this->unsetEnv("OVERLAY_OVERRIDES");

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // AGENT_DATA_STORE
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function agentDataStore_createsDirectoryWhenMissing(): void
    {
        $this->assertDirectoryDoesNotExist($this->dataStoreResolved);

        $this->setEnv("AGENT_DATA_STORE", $this->dataStorePath);

        Log::shouldReceive("info")
            ->once()
            ->withArgs(fn(string $msg) => str_contains($msg, "created at"));

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $this->assertDirectoryExists($this->dataStoreResolved);
    }

    #[Test]
    public function agentDataStore_doesNotRecreateExistingDirectory(): void
    {
        mkdir($this->dataStoreResolved, 0755, true);
        $this->assertDirectoryExists($this->dataStoreResolved);

        $this->setEnv("AGENT_DATA_STORE", $this->dataStorePath);

        Log::shouldReceive("info")->never();

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $this->assertDirectoryExists($this->dataStoreResolved);
    }

    // ─────────────────────────────────────────────────────────────────────
    // OVERLAY_WORKFLOWS
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function overlayWorkflows_createsDirectoryWhenMissing(): void
    {
        $this->assertDirectoryDoesNotExist($this->workflowOverlayResolved);

        $this->setEnv("OVERLAY_WORKFLOWS", $this->workflowOverlayPath);

        Log::shouldReceive("info")
            ->once()
            ->withArgs(fn(string $msg) => str_contains($msg, "created at"));

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $this->assertDirectoryExists($this->workflowOverlayResolved);
    }

    #[Test]
    public function overlayWorkflows_doesNotRecreateExistingDirectory(): void
    {
        mkdir($this->workflowOverlayResolved, 0755, true);

        $this->setEnv("OVERLAY_WORKFLOWS", $this->workflowOverlayPath);

        Log::shouldReceive("info")->never();

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $this->assertDirectoryExists($this->workflowOverlayResolved);
    }

    // ─────────────────────────────────────────────────────────────────────
    // OVERLAY_OVERRIDES
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function overlayOverrides_createsDirectoryWhenMissing(): void
    {
        $this->assertDirectoryDoesNotExist($this->skillOverlayResolved);

        $this->setEnv("OVERLAY_OVERRIDES", $this->skillOverlayPath);

        Log::shouldReceive("info")
            ->once()
            ->withArgs(fn(string $msg) => str_contains($msg, "created at"));

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $this->assertDirectoryExists($this->skillOverlayResolved);
    }

    #[Test]
    public function overlayOverrides_doesNotRecreateExistingDirectory(): void
    {
        mkdir($this->skillOverlayResolved, 0755, true);

        $this->setEnv("OVERLAY_OVERRIDES", $this->skillOverlayPath);

        Log::shouldReceive("info")->never();

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $this->assertDirectoryExists($this->skillOverlayResolved);
    }

    // ─────────────────────────────────────────────────────────────────────
    // All env vars unset
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function doesNothingWhenAllEnvVarsUnset(): void
    {
        $this->unsetEnv("AGENT_DATA_STORE");
        $this->unsetEnv("OVERLAY_WORKFLOWS");
        $this->unsetEnv("OVERLAY_OVERRIDES");

        Log::shouldReceive("info")->never();

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $this->assertDirectoryDoesNotExist($this->dataStoreResolved);
        $this->assertDirectoryDoesNotExist($this->workflowOverlayResolved);
        $this->assertDirectoryDoesNotExist($this->skillOverlayResolved);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function removeDir(string $path): void
    {
        if (is_dir($path)) {
            @rmdir($path);
        }
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    private function unsetEnv(string $key): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }
}
