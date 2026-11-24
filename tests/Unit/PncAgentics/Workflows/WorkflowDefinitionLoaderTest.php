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
 * Covers the 2-tier resolution order and fallback behaviour:
 *  1. config("pnc.workflows.override_dir") — application-level override.
 *  2. config("pnc.workflows.definitions_dir") — default definitions directory.
 *  3. Fallback to "blog_post.json" in the definitions directory.
 *  4. RuntimeException when nothing is found.
 *
 * Also covers:
 *  - decode(): valid JSON, invalid JSON exception.
 *
 * File I/O is directed to temporary directories. Config values are set
 * via config()->set() to control which directories the loader searches.
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
     * Temporary directory acting as the config override dir.
     */
    private string $overrideDir;

    private string $overrideRelative;

    private string $overrideAbsolute;

    /**
     * The system under test.
     */
    private WorkflowDefinitionLoader $sut;

    /**
     * Set up temp directories and config, instantiate the loader.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->definitionsDir = sys_get_temp_dir() . "/wf_defs_" . uniqid();
        mkdir($this->definitionsDir, 0755, true);

        $this->overrideRelative = "test_wf_override_" . uniqid();
        $this->overrideAbsolute = base_path($this->overrideRelative);
        mkdir($this->overrideAbsolute, 0755, true);

        config()->set("pnc.workflows.definitions_dir", $this->definitionsDir);
        config()->set("pnc.workflows.override_dir", null);

        $this->sut = new WorkflowDefinitionLoader();
    }

    /**
     * Clean up temp directories and reset config.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->definitionsDir);
        $this->removeDirectory($this->overrideAbsolute);

        config()->set("pnc.workflows.definitions_dir", null);
        config()->set("pnc.workflows.override_dir", null);

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Resolution order — config override (highest priority)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the file exists in the config override directory, it should
     * be loaded regardless of whether the file also exists in the default dir.
     *
     * NOTE: Production uses base_path($configOverride), so the config value
     * must be a RELATIVE path. The file is created at base_path($relative).
     */
    #[Test]
    public function load_prefersConfigOverrideOverDefaultDir(): void
    {
        $overrideContent = ["stages" => [["skill" => "override_skill"]]];
        $defaultContent = ["stages" => [["skill" => "default_skill"]]];

        // Use a relative path — production resolves it via base_path()
        $this->overrideRelative = "test_wf_override_" . uniqid();
        $this->overrideAbsolute = base_path($this->overrideRelative);
        mkdir($this->overrideAbsolute, 0755, true);

        file_put_contents(
            $this->overrideAbsolute . "/my_wf.json",
            json_encode($overrideContent),
        );
        file_put_contents(
            $this->definitionsDir . "/my_wf.json",
            json_encode($defaultContent),
        );

        // Set the config to the RELATIVE path
        config()->set("pnc.workflows.override_dir", $this->overrideRelative);

        $result = $this->sut->load("my_wf");

        $this->assertSame("override_skill", $result["stages"][0]["skill"]);

        $this->removeDirectory($this->overrideAbsolute);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Resolution order — default definitions directory
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the file is not in the config override but exists in the default
     * definitions directory, it should be loaded from there.
     */
    #[Test]
    public function load_fallsThroughToDefaultDefinitionsDir(): void
    {
        $defaultContent = ["stages" => [["skill" => "default_skill"]]];

        file_put_contents(
            $this->definitionsDir . "/my_wf.json",
            json_encode($defaultContent),
        );

        config()->set("pnc.workflows.override_dir", $this->overrideRelative);

        $result = $this->sut->load("my_wf");

        $this->assertSame("default_skill", $result["stages"][0]["skill"]);
    }

    /**
     * When no override dir is configured, the default dir is used directly.
     */
    #[Test]
    public function load_usesDefaultDirWhenNoOverrideConfigured(): void
    {
        $defaultContent = ["stages" => [["skill" => "default_skill"]]];

        file_put_contents(
            $this->definitionsDir . "/my_wf.json",
            json_encode($defaultContent),
        );

        // No override dir configured.
        $result = $this->sut->load("my_wf");

        $this->assertSame("default_skill", $result["stages"][0]["skill"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fallback to blog_post.json
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the named file is not found in any directory, the loader should
     * fall back to "blog_post.json" in the definitions directory.
     */
    #[Test]
    public function load_fallsBackToBlogPostJsonWhenNamedFileMissing(): void
    {
        $fallbackContent = ["stages" => [["skill" => "fallback_skill"]]];

        // Only blog_post.json exists — no "unknown_wf.json" anywhere.
        file_put_contents(
            $this->definitionsDir . "/blog_post.json",
            json_encode($fallbackContent),
        );

        $result = $this->sut->load("unknown_wf");

        $this->assertSame("fallback_skill", $result["stages"][0]["skill"]);
    }

    /**
     * The fallback should only apply to the default definitions directory,
     * not to the config override directory.
     */
    #[Test]
    public function load_fallbackOnlyAppliesToDefinitionsDir(): void
    {
        $fallbackContent = ["stages" => [["skill" => "fallback_skill"]]];

        file_put_contents(
            $this->definitionsDir . "/blog_post.json",
            json_encode($fallbackContent),
        );

        // Override dir is set (relative) but empty — should not find blog_post.json there.
        config()->set("pnc.workflows.override_dir", $this->overrideRelative);

        $result = $this->sut->load("nonexistent");

        // Should still fall back to blog_post.json in the definitions dir.
        $this->assertSame("fallback_skill", $result["stages"][0]["skill"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Exception: nothing found
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When neither the named file nor blog_post.json exists in any directory,
     * a RuntimeException should be thrown.
     */
    #[Test]
    public function load_throwsWhenNothingFoundAnywhere(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Workflow definition not found");

        $this->sut->load("totally_nonexistent");
    }

    /**
     * The exception message protected function setUp(): void
        {
            parent::setUp();

            $this->definitionsDir = sys_get_temp_dir() . "/wf_defs_" . uniqid();
            mkdir($this->definitionsDir, 0755, true);

            $this->overrideRelative = "test_wf_override_" . uniqid();
            $this->overrideAbsolute = base_path($this->overrideRelative);
            mkdir($this->overrideAbsolute, 0755, true);

            config()->set("pnc.workflows.definitions_dir", $this->definitionsDir);
            config()->set("pnc.workflows.override_dir", null);

            $this->sut = new WorkflowDefinitionLoader();
        }

        protected function tearDown(): void
        {
            $this->removeDirectory($this->definitionsDir);
            $this->removeDirectory($this->overrideAbsolute);

            config()->set("pnc.workflows.definitions_dir", null);
            config()->set("pnc.workflows.override_dir", null);

            parent::tearDown();
        } should include the searched paths for debugging.
     * NOTE: Production hardcodes "default.json" in the message even though
     * the actual fallback file checked is "blog_post.json".
     */
    #[Test]
    public function load_exceptionMessageIncludesSearchedPaths(): void
    {
        try {
            $this->sut->load("missing");
            $this->fail("Expected RuntimeException was not thrown.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("missing.json", $e->getMessage());
            // Production message says "default.json" (hardcoded), not "blog_post.json"
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
            "persist" => ["path" => "/out"],
        ];

        file_put_contents(
            $this->definitionsDir . "/valid.json",
            json_encode($workflow),
        );

        $result = $this->sut->load("valid");

        $this->assertCount(2, $result["stages"]);
        $this->assertSame("a", $result["stages"][0]["skill"]);
        $this->assertSame("/out", $result["persist"]["path"]);
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
     * Invalid JSON in the config override should also throw (not silently fall through).
     */
    #[Test]
    public function load_throwsOnInvalidJsonInOverride(): void
    {
        file_put_contents(
            $this->overrideAbsolute . "/broken.json",
            "not json at all",
        );

        config()->set("pnc.workflows.override_dir", $this->overrideRelative);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid JSON");

        $this->sut->load("broken");
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

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
