<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Services;

use App\PncAgentics\Services\SkillRegistry;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see SkillRegistry}.
 *
 * Tests CRUD operations (all, get, create, update, delete) and the
 * keyToPath() resolution logic.
 *
 * File operations are directed to a temporary directory via a test
 * subclass that overrides keyToPath(). Config is set directly in setUp.
 *
 * @covers \App\PncAgentics\Services\SkillRegistry
 */
class SkillRegistryTest extends TestCase
{
    /**
     * The system under test (test subclass with temp dir).
     */
    private SkillRegistry $registry;

    /**
     * Temporary directory used as the skills base path.
     */
    private string $tempDir;

    /**
     * Set up the test environment: create a temp directory, configure
     * the pnc.skills config, and instantiate the test registry.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir =
            sys_get_temp_dir() . "/skill_registry_test_" . uniqid();
        mkdir($this->tempDir, 0755, true);

        // Seed config with a known skill for get()/update()/delete() tests.
        config()->set("pnc.skills", [
            "test.existing_skill" => "Existing skill content",
        ]);

        // Use a test subclass that redirects keyToPath to our temp dir.
        $this->registry = new class ($this->tempDir) extends SkillRegistry {
            public function __construct(protected string $testBasePath) {}

            protected function keyToPath(string $key): string
            {
                if (str_ends_with($key, ".skills")) {
                    $key = substr($key, 0, -7);
                }
                $relative = str_replace(".", DIRECTORY_SEPARATOR, $key) . ".md";
                return $this->testBasePath . "/" . $relative;
            }
        };
    }

    /**
     * Clean up the temporary directory after each test.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // all()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * all() should return the full pnc.skills config array.
     */
    #[Test]
    public function all_returnsConfigValue(): void
    {
        $result = $this->registry->all();

        $this->assertIsArray($result);
        $this->assertArrayHasKey("test.existing_skill", $result);
        $this->assertSame(
            "Existing skill content",
            $result["test.existing_skill"],
        );
    }

    /**
     * all() should return an empty array when no skills are registered.
     */
    #[Test]
    public function all_returnsEmptyArrayWhenNoSkills(): void
    {
        config()->set("pnc.skills", []);

        $this->assertSame([], $this->registry->all());
    }

    // ─────────────────────────────────────────────────────────────────────
    // get()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * get() should return the content for an existing key.
     */
    #[Test]
    public function get_returnsContentForExistingKey(): void
    {
        $this->assertSame(
            "Existing skill content",
            $this->registry->get("test.existing_skill"),
        );
    }

    /**
     * get() should return null for a non-existent key.
     */
    #[Test]
    public function get_returnsNullForMissingKey(): void
    {
        $this->assertNull($this->registry->get("test.nonexistent"));
    }

    // ─────────────────────────────────────────────────────────────────────
    // create()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * create() should write a new file to disk, add the key to config,
     * and make the content retrievable via get().
     */
    #[Test]
    public function create_writesFileAndUpdatesConfig(): void
    {
        $this->registry->create("test.new_skill", "New skill content");

        // Config updated
        $this->assertSame(
            "New skill content",
            $this->registry->get("test.new_skill"),
        );

        // File exists on disk
        $expectedPath = $this->tempDir . "/test/new_skill.md";
        $this->assertFileExists($expectedPath);
        $this->assertSame(
            "New skill content",
            file_get_contents($expectedPath),
        );
    }

    /**
     * create() should create parent directories when they do not exist.
     */
    #[Test]
    public function create_createsParentDirectories(): void
    {
        $this->registry->create("a.b.c.d.skill", "Deep content");

        $expectedPath = $this->tempDir . "/a/b/c/d/skill.md";
        $this->assertFileExists($expectedPath);
    }

    /**
     * create() should throw InvalidArgumentException when the key already exists.
     */
    #[Test]
    public function create_throwsWhenKeyAlreadyExists(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("already exists");

        $this->registry->create("test.existing_skill", "Should fail");
    }

    // ─────────────────────────────────────────────────────────────────────
    // update()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * update() should overwrite the file content and refresh the config entry.
     */
    #[Test]
    public function update_overwritesFileAndConfig(): void
    {
        // Create the file first so update() can find it.
        $path = $this->tempDir . "/test/existing_skill.md";
        File::makeDirectory(dirname($path), 0755, true);
        File::put($path, "Existing skill content");

        $this->registry->update("test.existing_skill", "Updated content");

        // Config updated
        $this->assertSame(
            "Updated content",
            $this->registry->get("test.existing_skill"),
        );

        // File on disk updated
        $this->assertSame("Updated content", file_get_contents($path));
    }

    /**
     * update() should throw InvalidArgumentException when the key does not exist.
     */
    #[Test]
    public function update_throwsWhenKeyDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("does not exist");

        $this->registry->update("test.nonexistent", "Should fail");
    }

    // ─────────────────────────────────────────────────────────────────────
    // delete()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * delete() should remove the file from disk and the key from config.
     */
    #[Test]
    public function delete_removesFileAndConfigEntry(): void
    {
        // Create the file so it exists on disk.
        $path = $this->tempDir . "/test/existing_skill.md";
        File::makeDirectory(dirname($path), 0755, true);
        File::put($path, "Existing skill content");

        $this->registry->delete("test.existing_skill");

        // Removed from config
        $this->assertNull($this->registry->get("test.existing_skill"));
        $this->assertArrayNotHasKey(
            "test.existing_skill",
            $this->registry->all(),
        );

        // Removed from disk
        $this->assertFileDoesNotExist($path);
    }

    /**
     * delete() should throw InvalidArgumentException when the key does not exist.
     */
    #[Test]
    public function delete_throwsWhenKeyDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("does not exist");

        $this->registry->delete("test.nonexistent");
    }

    // ─────────────────────────────────────────────────────────────────────
    // keyToPath()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * keyToPath() should convert dot-notation to directory separators
     * and append .md.
     */
    #[Test]
    #[DataProvider("keyToPathProvider")]
    public function keyToPath_convertsDotsToDirectorySeparators(
        string $key,
        string $expectedRelative,
    ): void {
        $method = new \ReflectionMethod(SkillRegistry::class, "keyToPath");
        $method->setAccessible(true);

        $result = $method->invoke($this->registry, $key);

        $this->assertStringEndsWith($expectedRelative, $result);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function keyToPathProvider(): array
    {
        return [
            "simple key" => [
                "track.core_writing",
                "track" . DIRECTORY_SEPARATOR . "core_writing.md",
            ],
            "deep key" => [
                "a.b.c.d",
                "a" .
                DIRECTORY_SEPARATOR .
                "b" .
                DIRECTORY_SEPARATOR .
                "c" .
                DIRECTORY_SEPARATOR .
                "d.md",
            ],
            "key with .skills suffix strips it" => [
                "track.core_writing.topic_opener.skills",
                "track" .
                DIRECTORY_SEPARATOR .
                "core_writing" .
                DIRECTORY_SEPARATOR .
                "topic_opener.md",
            ],
        ];
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
