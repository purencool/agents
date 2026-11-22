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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see SkillRegistry}.
 *
 * The registry stores skill definitions as arrays under dot-notation keys.
 * Each entry has the shape:
 *
 *   [
 *       "name"                => string,  // The dot-notation key itself.
 *       "system_instructions" => string,  // The raw markdown prompt content.
 *   ]
 *
 * Covers:
 *  - create(): stores a skill, writes to disk, throws on duplicate key.
 *  - get(): returns the stored array, null for missing key.
 *  - update(): replaces system_instructions, throws for missing key.
 *  - delete(): removes the skill and its file, throws for missing key.
 *
 * Files created on disk during tests are cleaned up in tearDown().
 *
 * @covers \App\PncAgentics\Services\SkillRegistry
 */
class SkillRegistryTest extends TestCase
{
    /**
     * The system under test.
     */
    private SkillRegistry $registry;

    /**
     * Keys created during this test (for cleanup).
     *
     * @var string[]
     */
    private array $createdKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SkillRegistry();
        $this->createdKeys = [];
    }

    protected function tearDown(): void
    {
        // Remove any files created on disk during the test.
        foreach ($this->createdKeys as $key) {
            $path = app_path(
                "PncAgentics/Skills/" . str_replace(".", "/", $key) . ".md",
            );
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        // Reset the config to a clean state.
        config()->set("pnc.skills", null);

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // get()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * get() should return the stored array config for an existing key,
     * with "name" matching the key and "system_instructions" matching
     * the content passed to create().
     */
    #[Test]
    public function get_returnsConfigForExistingKey(): void
    {
        $this->registry->create(
            "test.existing_skill",
            "Existing skill content",
        );
        $this->createdKeys[] = "test.existing_skill";

        $result = $this->registry->get("test.existing_skill");

        $this->assertIsArray($result);
        $this->assertSame("test.existing_skill", $result["name"]);
        $this->assertSame(
            "Existing skill content",
            $result["system_instructions"],
        );
    }

    /**
     * get() should return null when the key has not been registered.
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
     * create() should store the skill and make it immediately retrievable
     * via get() with the correct array shape.
     */
    #[Test]
    public function create_storesContentAndMakesItRetrievable(): void
    {
        $this->registry->create("test.new_skill", "New skill content");
        $this->createdKeys[] = "test.new_skill";

        $result = $this->registry->get("test.new_skill");

        $this->assertIsArray($result);
        $this->assertSame("New skill content", $result["system_instructions"]);
    }

    /**
     * create() should throw InvalidArgumentException when the key already
     * exists, directing the caller to use update() instead.
     */
    #[Test]
    public function create_throwsWhenKeyAlreadyExists(): void
    {
        $this->registry->create("test.duplicate", "First content");
        $this->createdKeys[] = "test.duplicate";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("already exists");

        $this->registry->create("test.duplicate", "Second content");
    }

    // ─────────────────────────────────────────────────────────────────────
    // update()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * update() should replace the system_instructions of an existing skill
     * while preserving the "name" field.
     */
    #[Test]
    public function update_replacesExistingContent(): void
    {
        $this->registry->create("test.updatable", "Original content");
        $this->createdKeys[] = "test.updatable";

        $this->registry->update("test.updatable", "Updated content");

        $result = $this->registry->get("test.updatable");
        $this->assertSame("test.updatable", $result["name"]);
        $this->assertSame("Updated content", $result["system_instructions"]);
    }

    /**
     * update() should throw InvalidArgumentException when the key does not
     * exist.
     */
    #[Test]
    public function update_throwsWhenKeyDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("does not exist");

        $this->registry->update("test.never_created", "Content");
    }

    // ─────────────────────────────────────────────────────────────────────
    // delete()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * delete() should remove an existing skill so that subsequent get()
     * calls return null.
     */
    #[Test]
    public function delete_removesExistingSkill(): void
    {
        $this->registry->create("test.to_delete", "Content to delete");
        $this->createdKeys[] = "test.to_delete";
        $this->assertNotNull($this->registry->get("test.to_delete"));

        $this->registry->delete("test.to_delete");

        $this->assertNull($this->registry->get("test.to_delete"));
    }

    /**
     * delete() should throw InvalidArgumentException when the key does not
     * exist.
     */
    #[Test]
    public function delete_throwsWhenSkillDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("does not exist");

        $this->registry->delete("test.never_created");
    }
}
