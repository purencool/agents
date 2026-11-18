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
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    private string $relativePath;
    private string $resolvedPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relativePath = "storage/test_pnc_" . uniqid();
        $this->resolvedPath = base_path($this->relativePath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->resolvedPath)) {
            @rmdir($this->resolvedPath);
        }

        putenv("AGENT_DATA_STORE");

        parent::tearDown();
    }

    public function test_creates_directory_when_missing(): void
    {
        $this->assertDirectoryDoesNotExist($this->resolvedPath);

        putenv("AGENT_DATA_STORE={$this->relativePath}");

        Log::shouldReceive("info")
            ->once()
            ->withArgs(fn(string $msg) => str_contains($msg, "Created at"));

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $this->assertDirectoryExists($this->resolvedPath);
    }

    public function test_does_not_recreate_existing_directory(): void
    {
        mkdir($this->resolvedPath, 0755, true);
        $this->assertDirectoryExists($this->resolvedPath);

        putenv("AGENT_DATA_STORE={$this->relativePath}");

        Log::shouldReceive("info")->never();

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $this->assertDirectoryExists($this->resolvedPath);
    }

    public function test_does_nothing_when_env_not_set(): void
    {
        putenv("AGENT_DATA_STORE");

        Log::shouldReceive("info")->never();

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $this->assertTrue(true);
    }
}
