<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Workflows;

use App\PncAgentics\Workflows\WorkflowDefinitionLoader;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit tests for {@see WorkflowDefinitionLoader}.
 *
 * Covers the 3-tier resolution order and fallback behaviour:
 *  1. OVERLAY_WORKFLOWS env var (highest priority).
 *  2. config("pnc.workflows.override_dir") (application-level override).
 *  3. Default definitions directory (baked into the image).
 *  4. Fallback to "default.json" in the definitions directory.
 *  5. RuntimeException when nothing is found.
 *
 * Also covers:
 *  - decode(): valid JSON, invalid JSON exception.
 *
 * File I/O is directed to temporary directories. Environment variables are
 * managed via setEnv()/unsetEnv() helpers to ensure Laravel's env() sees
 * the values.
 *
 * @covers \App\PncAgentics\Workflows\WorkflowDefinitionLoader
 */
class WorkflowDefinitionLoaderTest extends TestCase
{
    /**
     * Temporary directory acting as the default definitions dir.
     */
    private string $definitionsDir;

    /**
     * Temporary directory acting as the overlay dir (relative, resolved via base_path()).
     */
    private string $overlayRelative;

    /**
     * Absolute path to the overlay temp dir.
     */
    private string $overlayAbsolute;

    /**
     * Temporary directory acting as the config override dir.
     */
    private string $configOverrideDir;

    /**
     * The system under test.
     */
    private WorkflowDefinitionLoader $sut;

    /**
     * Set up temp directories, clear env/config overrides, instantiate the loader.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->definitionsDir = sys_get_temp_dir() . "/wf_defs_" . uniqid();
        mkdir($this->definitionsDir, 0755, true);

        $this->overlayRelative = "storage/test_wf_overlay_" . uniqid();
        $this->overlayAbsolute = base_path($this->overlayRelative);
        mkdir($this->overlayAbsolute, 0755, true);

        $this->configOverrideDir =
            sys_get_temp_dir() . "/wf_config_override_" . uniqid();
        mkdir($this->configOverrideDir, 0755, true);

        $this->sut = new WorkflowDefinitionLoader($this->definitionsDir);

        // Start clean.
        $this->unsetEnv("OVERLAY_WORKFLOWS");
        config()->set("pnc.workflows.override_dir", null);
    }

    /**
     * Clean up temp directories and reset env/config.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->definitionsDir);
        $this->removeDirectory($this->overlayAbsolute);
        $this->removeDirectory($this->configOverrideDir);

        $this->unsetEnv("OVERLAY_WORKFLOWS");
        config()->set("pnc.workflows.override_dir", null);

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Resolution order — overlay (highest priority)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the file exists in the OVERLAY_WORKFLOWS directory, it should
     * be loaded regardless of whether the file also exists in lower-priority
     * directories.
     */
    #[Test]
    public function load_prefersOverlayOverAllOtherSources(): void
    {
        $overlayContent = ["stages" => [["skill" => "overlay_skill"]]];
        $defaultContent = ["stages" => [["skill" => "default_skill"]]];

        file_put_contents(
            $this->overlayAbsolute . "/my_wf.json",
            json_encode($overlayContent),
        );
        file_put_contents(
            $this->definitionsDir . "/my_wf.json",
            json_encode($defaultContent),
        );

        $this->setEnv("OVERLAY_WORKFLOWS", $this->overlayRelative);

        $result = $this->sut->load("my_wf");

        $this->assertSame("overlay_skill", $result["stages"][0]["skill"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Resolution order — config override
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the file is not in the overlay but exists in the config override
     * directory, it should be loaded from there.
     */
    #[Test]
    public function load_fallsThroughToConfigOverrideWhenOverlayMissing(): void
    {
        $configContent = ["stages" => [["skill" => "config_skill"]]];
        $defaultContent = ["stages" => [["skill" => "default_skill"]]];

        file_put_contents(
            $this->configOverrideDir . "/my_wf.json",
            json_encode($configContent),
        );
        file_put_contents(
            $this->definitionsDir . "/my_wf.json",
            json_encode($defaultContent),
        );

        // Overlay is set but file doesn't exist there.
        $this->setEnv("OVERLAY_WORKFLOWS", $this->overlayRelative);
        config()->set("pnc.workflows.override_dir", $this->configOverrideDir);

        $result = $this->sut->load("my_wf");

        $this->assertSame("config_skill", $result["stages"][0]["skill"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Resolution order — default definitions directory
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the file is not in overlay or config override, it should be
     * loaded from the default definitions directory.
     */
    #[Test]
    public function load_fallsThroughToDefaultDefinitionsDir(): void
    {
        $defaultContent = ["stages" => [["skill" => "default_skill"]]];

        file_put_contents(
            $this->definitionsDir . "/my_wf.json",
            json_encode($defaultContent),
        );

        // No overlay, no config override.
        $result = $this->sut->load("my_wf");

        $this->assertSame("default_skill", $result["stages"][0]["skill"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fallback to default.json
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the named file is not found in any directory, the loader should
     * fall back to "default.json" in the definitions directory.
     */
    #[Test]
    public function load_fallsBackToDefaultJsonWhenNamedFileMissing(): void
    {
        $defaultJson = ["stages" => [["skill" => "fallback_skill"]]];

        // Only default.json exists — no "unknown_wf.json" anywhere.
        file_put_contents(
            $this->definitionsDir . "/default.json",
            json_encode($defaultJson),
        );

        $result = $this->sut->load("unknown_wf");

        $this->assertSame("fallback_skill", $result["stages"][0]["skill"]);
    }

    /**
     * The fallback should only apply to the default definitions directory,
     * not to the overlay or config override.
     */
    #[Test]
    public function load_fallbackOnlyAppliesToDefinitionsDir(): void
    {
        $defaultJson = ["stages" => [["skill" => "fallback_skill"]]];

        file_put_contents(
            $this->definitionsDir . "/default.json",
            json_encode($defaultJson),
        );

        // Overlay is set but empty — should not find default.json there.
        $this->setEnv("OVERLAY_WORKFLOWS", $this->overlayRelative);

        $result = $this->sut->load("nonexistent");

        // Should still fall back to default.json in the definitions dir.
        $this->assertSame("fallback_skill", $result["stages"][0]["skill"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Exception: nothing found
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When neither the named file nor default.json exists in any directory,
     * a RuntimeException should be thrown listing all searched paths.
     */
    #[Test]
    public function load_throwsWhenNothingFoundAnywhere(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Workflow definition not found");

        $this->sut->load("totally_nonexistent");
    }

    /**
     * The exception message should include the searched paths for debugging.
     */
    #[Test]
    public function load_exceptionMessageIncludesSearchedPaths(): void
    {
        try {
            $this->sut->load("missing");
            $this->fail("Expected RuntimeException was not thrown.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("missing.json", $e->getMessage());
            $this->assertStringContainsString("default.json", $e->getMessage());
            $this->assertStringContainsString(
                $this->definitionsDir,
                $e->getMessage(),
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // decode() — valid JSON
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A valid JSON file should be decoded into an associative array.
     */
    #[Test]
    public function load_decodesValidJsonIntoArray(): void
    {
        $workflow = [
            "stages" => [
                ["skill" => "a", "output_key" => "out_a"],
                ["skill" => "b", "output_key" => "out_b"],
            ],
            "persist" => ["path" => "/out", "filename" => "result.md"],
        ];

        file_put_contents(
            $this->definitionsDir . "/valid.json",
            json_encode($workflow),
        );

        $result = $this->sut->load("valid");

        $this->assertCount(2, $result["stages"]);
        $this->assertSame("a", $result["stages"][0]["skill"]);
        $this->assertSame("result.md", $result["persist"]["filename"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // decode() — invalid JSON
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the file contains invalid JSON, a RuntimeException should be
     * thrown with the JSON error message.
     */
    #[Test]
    public function load_throwsOnInvalidJson(): void
    {
        file_put_contents(
            $this->definitionsDir . "/broken.json",
            "{this is not valid json",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid JSON");

        $this->sut->load("broken");
    }

    /**
     * Invalid JSON in the overlay should also throw (not silently fall through).
     */
    #[Test]
    public function load_throwsOnInvalidJsonInOverlay(): void
    {
        file_put_contents(
            $this->overlayAbsolute . "/broken.json",
            "not json at all",
        );

        $this->setEnv("OVERLAY_WORKFLOWS", $this->overlayRelative);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid JSON");

        $this->sut->load("broken");
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Set an environment variable in all locations Laravel's env() reads from.
     */
    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    /**
     * Unset an environment variable from all locations.
     */
    private function unsetEnv(string $key): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
