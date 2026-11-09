<?php

namespace Tests\Unit\PncServices\Agent\Actions;

use App\PncServices\Agent\Actions\CreateAgentStructureAction;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Unit tests for {@see CreateAgentStructureAction}.
 *
 * Verifies directory creation, file generation, naming conventions,
 * and the returned structure shape. Uses a temp directory to avoid
 * polluting the real data store.
 *
 * @covers \App\PncServices\Agent\Actions\CreateAgentStructureAction
 */
class CreateAgentStructureActionTest extends TestCase
{
    private CreateAgentStructureAction $action;

    private string $tempBase;

    /**
     * Shared fixture payload loaded from tests/Fixtures/input_data/agent_data_payload.php.
     *
     * @var array{title: string, description: string, domains: array}
     */
    private array $payload;

    /**
     * Sets up a temp directory and points the action at it.
     */
    protected function setUp(): void
    {
        $this->payload = require __DIR__ .
            "/../../../../Fixtures/input_data/agent_data_payload.php";

        $this->tempBase = sys_get_temp_dir() . "/agent_test_" . uniqid();
        mkdir($this->tempBase, 0755, true);

        $this->action = new class ($this->tempBase) extends
            CreateAgentStructureAction
        {
            public function __construct(string $basePath)
            {
                $this->basePath = $basePath;
            }
        };
    }

    /**
     * Recursively removes the temp directory after each test.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempBase);
    }

    /**
     * Verifies the returned array has all documented top-level keys.
     */
    #[Test]
    public function execute_returns_documented_structure_keys(): void
    {
        $result = $this->action->execute($this->payload);

        $this->assertArrayHasKey("uuid", $result);
        $this->assertArrayHasKey("domain_dir", $result);
        $this->assertArrayHasKey("assets_dir", $result);
        $this->assertArrayHasKey("research_dir", $result);
        $this->assertArrayHasKey("tracks", $result);
    }

    /**
     * Verifies the UUID is a valid v4 UUID string.
     */
    #[Test]
    public function execute_returns_valid_uuid(): void
    {
        $result = $this->action->execute($this->payload);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result["uuid"],
        );
    }

    /**
     * Verifies that the domain, assets, and research directories are created on disk.
     */
    #[Test]
    public function execute_creates_directory_structure(): void
    {
        $result = $this->action->execute($this->payload);

        $this->assertDirectoryExists($result["domain_dir"]);
        $this->assertDirectoryExists($result["assets_dir"]);
        $this->assertDirectoryExists($result["research_dir"]);
    }

    /**
     * Verifies that research.json is created and contains the UUID.
     */
    #[Test]
    public function execute_creates_research_json_with_uuid(): void
    {
        $result = $this->action->execute($this->payload);

        $researchPath = "{$result["research_dir"]}/research.json";

        $this->assertFileExists($researchPath);

        $data = json_decode(file_get_contents($researchPath), true);
        $this->assertSame($result["uuid"], $data["uuid"]);
    }

    /**
     * Verifies that one file set (json + md + description) is created per
     * domain/type/lang combination.
     */
    #[Test]
    public function execute_creates_file_set_per_track(): void
    {
        $result = $this->action->execute($this->payload);

        // 2 domains × 1 type × 1 lang = 2 tracks
        $this->assertCount(2, $result["tracks"]);

        foreach ($result["tracks"] as $track) {
            $this->assertFileExists($track["content_json"]);
            $this->assertFileExists($track["content_md"]);
            $this->assertFileExists($track["description_md"]);
        }
    }

    /**
     * Verifies the content.json file contains all documented metadata fields.
     */
    #[Test]
    public function execute_content_json_contains_all_metadata(): void
    {
        $result = $this->action->execute($this->payload);

        $track = $result["tracks"]["blog.com/blog/en"];
        $data = json_decode(file_get_contents($track["content_json"]), true);

        $expectedKeys = [
            "uuid",
            "description",
            "title",
            "domain",
            "type",
            "template",
            "track",
            "track_type",
            "workflow",
            "lang",
            "html_content",
            "markdown_content",
            "fields",
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        $this->assertSame("blog.com", $data["domain"]);
        $this->assertSame("blog", $data["type"]);
        $this->assertSame("social", $data["template"]);
        $this->assertSame("social_media", $data["track"]);
        $this->assertSame("default", $data["workflow"]);
        $this->assertSame("en", $data["lang"]);
        $this->assertSame($result["uuid"], $data["uuid"]);
    }

    /**
     * Verifies the description.md file is seeded with the payload description.
     */
    #[Test]
    public function execute_description_md_contains_payload_description(): void
    {
        $result = $this->action->execute($this->payload);

        $track = $result["tracks"]["blog.com/blog/en"];
        $content = file_get_contents($track["description_md"]);

        $this->assertSame($this->payload["description"], $content);
    }

    /**
     * Verifies that comma-separated languages produce separate file sets.
     */
    #[Test]
    public function execute_expands_comma_separated_languages(): void
    {
        $payload = [
            "title" => "Multi Lang Test",
            "description" => "Test description",
            "domains" => [
                "example.com" => [
                    "docs" => [
                        "content_tracks" => [
                            "config" => [
                                "type" => "document",
                                "workflow" => "default",
                                "template" => "doc",
                                "track" => "documentation",
                                "lang" => "en, ru",
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->action->execute($payload);

        $this->assertCount(2, $result["tracks"]);
        $this->assertArrayHasKey("example.com/docs/en", $result["tracks"]);
        $this->assertArrayHasKey("example.com/docs/ru", $result["tracks"]);
    }

    /**
     * Verifies the file naming convention: {domainSlug}-{titleSlug}-{typeSlug}-{lang}.
     */
    #[Test]
    public function execute_uses_correct_file_naming_convention(): void
    {
        $result = $this->action->execute($this->payload);
        $track = $result["tracks"]["blog.com/blog/en"];
        $basename = basename($track["content_json"], "-content.json");

        $this->assertMatchesRegularExpression(
            '/^blogcom-.+-blog-en$/',
            $basename,
        );
    }

    /**
     * Verifies the domain_dir path contains the year and UUID segments.
     */
    #[Test]
    public function execute_domain_dir_contains_year_and_uuid(): void
    {
        $result = $this->action->execute($this->payload);
        $year = Carbon::now()->format("Y");

        $this->assertStringContainsString(
            "/domains/{$year}/{$result["uuid"]}/",
            $result["domain_dir"],
        );
    }

    /**
     * Recursively deletes a directory tree.
     *
     * @param string $dir Absolute path to remove.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
