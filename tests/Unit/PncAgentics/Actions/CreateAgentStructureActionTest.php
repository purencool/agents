<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Actions;

use App\PncAgentics\Actions\CreateAgentStructureAction;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Unit tests for {@see CreateAgentStructureAction}.
 *
 * Verifies directory creation, file generation, naming conventions,
 * two-level track structure (domain → type-lang), and the returned
 * structure shape. Uses a temp directory to avoid polluting the
 * real data store.
 *
 * @covers \App\PncAgentics\Actions\CreateAgentStructureAction
 */
class CreateAgentStructureActionTest extends TestCase
{
    private CreateAgentStructureAction $action;

    private string $tempBase;

    /**
     * Shared fixture payload loaded from tests/Fixtures/input_data/agent_data_payload.php.
     *
     * @var array{title: string, description: string, categories: string, domains: array}
     */
    private array $payload;

    /**
     * Sets up a temp directory and points the action at it.
     */
    protected function setUp(): void
    {
        $this->payload = require __DIR__ .
            "/../../../Fixtures/input_data/agent_data_payload.php";

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

    // ─── Top-level structure keys ────────────────────────────────────────

    /**
     * Verifies the returned array has all documented top-level keys.
     */
    #[Test]
    public function execute_returns_documented_structure_keys(): void
    {
        $result = $this->action->execute($this->payload);

        $expectedKeys = [
            "uuid",
            "title",
            "title_slug",
            "description",
            "categories",
            "domain_dir",
            "assets_dir",
            "research_dir",
            "tracks",
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result);
        }
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
     * Verifies title_slug is the slugified title.
     */
    #[Test]
    public function execute_returns_title_slug(): void
    {
        $result = $this->action->execute($this->payload);

        $expectedSlug = \Illuminate\Support\Str::slug(
            $this->payload["title"],
            "_",
        );
        $this->assertSame($expectedSlug, $result["title_slug"]);
    }

    /**
     * Verifies categories is split from the comma-separated string.
     */
    #[Test]
    public function execute_splits_categories(): void
    {
        $result = $this->action->execute($this->payload);

        $expected = array_map(
            "trim",
            explode(",", $this->payload["categories"] ?? "none"),
        );
        $this->assertSame($expected, $result["categories"]);
    }

    // ─── Directory structure ─────────────────────────────────────────────

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
     * Verifies the domain_dir path contains /content/{year}/{uuid}/{dateTime}.
     */
    #[Test]
    public function execute_domain_dir_contains_year_and_uuid(): void
    {
        $result = $this->action->execute($this->payload);
        $year = Carbon::now()->format("Y");

        $this->assertStringContainsString(
            "/content/{$year}/{$result["uuid"]}/",
            $result["domain_dir"],
        );
    }

    /**
     * Verifies the research_dir is a subdirectory of domain_dir.
     */
    #[Test]
    public function execute_research_dir_is_subdirectory_of_domain_dir(): void
    {
        $result = $this->action->execute($this->payload);

        $this->assertStringStartsWith(
            $result["domain_dir"],
            $result["research_dir"],
        );
        $this->assertStringEndsWith("/research", $result["research_dir"]);
    }

    // ─── Track structure ─────────────────────────────────────────────────

    /**
     * Verifies tracks is a two-level array: domain → type-lang.
     */
    #[Test]
    public function execute_tracks_is_two_level_array(): void
    {
        $result = $this->action->execute($this->payload);

        $this->assertIsArray($result["tracks"]);

        foreach ($result["tracks"] as $domain => $domainTracks) {
            $this->assertIsArray($domainTracks);
            foreach ($domainTracks as $trackKey => $track) {
                $this->assertIsArray($track);
                // Track key format: {typeSlug}-{lang}
                $this->assertMatchesRegularExpression(
                    '/^[a-z0-9_]+-[a-z]{2}$/',
                    $trackKey,
                );
            }
        }
    }

    /**
     * Verifies that one file set (json + md + description) is created per
     * domain/type/lang combination.
     */
    #[Test]
    public function execute_creates_file_set_per_track(): void
    {
        $result = $this->action->execute($this->payload);

        $totalTracks = 0;
        foreach ($result["tracks"] as $domainTracks) {
            foreach ($domainTracks as $track) {
                $totalTracks++;
                $this->assertFileExists($track["content_path_json"]);
                $this->assertFileExists($track["content_path_markdown"]);
                $this->assertFileExists($track["content_description"]);
            }
        }

        $this->assertGreaterThan(0, $totalTracks);
    }

    /**
     * Verifies each track entry has all documented fields.
     */
    #[Test]
    public function execute_track_contains_all_documented_fields(): void
    {
        $result = $this->action->execute($this->payload);

        $expectedKeys = [
            "uuid",
            "title",
            "categories",
            "workflow",
            "lang",
            "domain",
            "context",
            "type",
            "template",
            "track",
            "track_type",
            "content_json",
            "content_path_json",
            "content_html",
            "content_path_html",
            "content_markdown",
            "content_path_markdown",
            "content_description",
        ];

        foreach ($result["tracks"] as $domainTracks) {
            foreach ($domainTracks as $track) {
                foreach ($expectedKeys as $key) {
                    $this->assertArrayHasKey(
                        $key,
                        $track,
                        "Missing key: {$key}",
                    );
                }
            }
        }
    }

    /**
     * Verifies the context field is populated from the type config.
     */
    #[Test]
    public function execute_track_context_matches_payload(): void
    {
        $result = $this->action->execute($this->payload);

        foreach ($this->payload["domains"] as $domain => $types) {
            foreach ($types as $type => $typeConfig) {
                $expectedContext = $typeConfig["context"] ?? "";
                $typeSlug = \Illuminate\Support\Str::slug($type, "_");

                $domainTracks = $result["tracks"][$domain] ?? [];
                $found = false;
                foreach ($domainTracks as $trackKey => $track) {
                    if (str_starts_with($trackKey, $typeSlug)) {
                        $this->assertSame(
                            $expectedContext,
                            $track["context"],
                            "Context mismatch for {$domain}/{$trackKey}",
                        );
                        $found = true;
                    }
                }
                $this->assertTrue(
                    $found,
                    "No track found for {$domain}/{$type}",
                );
            }
        }
    }

    // ─── Content JSON file ───────────────────────────────────────────────

    /**
     * Verifies the content.json file wraps the track data under "content".
     */
    #[Test]
    public function execute_content_json_wraps_track_under_content_key(): void
    {
        $result = $this->action->execute($this->payload);

        // Grab the first track
        $firstDomain = array_key_first($result["tracks"]);
        $firstDomainTracks = $result["tracks"][$firstDomain];
        $firstTrackKey = array_key_first($firstDomainTracks);
        $track = $firstDomainTracks[$firstTrackKey];

        $data = json_decode(
            (string) file_get_contents($track["content_path_json"]),
            true,
        );

        // Structure: [["content" => trackData]]
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertArrayHasKey("content", $data[0]);
        $this->assertSame($track["uuid"], $data[0]["content"]["uuid"]);
        $this->assertSame($track["domain"], $data[0]["content"]["domain"]);
    }

    // ─── Description file ────────────────────────────────────────────────

    /**
     * Verifies the description.md file is seeded with the payload description.
     */
    #[Test]
    public function execute_description_md_contains_payload_description(): void
    {
        $result = $this->action->execute($this->payload);

        foreach ($result["tracks"] as $domainTracks) {
            foreach ($domainTracks as $track) {
                $content = (string) file_get_contents(
                    $track["content_description"],
                );
                $this->assertSame(
                    $this->payload["description"],
                    $content,
                    "Description mismatch for {$track["domain"]}/{$track["type"]}",
                );
            }
        }
    }

    // ─── Multi-language expansion ────────────────────────────────────────

    /**
     * Verifies that comma-separated languages produce separate file sets.
     */
    #[Test]
    public function execute_expands_comma_separated_languages(): void
    {
        $payload = [
            "title" => "Multi Lang Test",
            "description" => "Test description",
            "categories" => "Test",
            "domains" => [
                "example.com" => [
                    "docs" => [
                        "context" => "Test context",
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

        $this->assertArrayHasKey("example.com", $result["tracks"]);
        $this->assertArrayHasKey("docs-en", $result["tracks"]["example.com"]);
        $this->assertArrayHasKey("docs-ru", $result["tracks"]["example.com"]);
        $this->assertCount(2, $result["tracks"]["example.com"]);
    }

    // ─── File naming ─────────────────────────────────────────────────────

    /**
     * Verifies the file naming convention: {domainSlug}-{titleSlug}-{typeSlug}-{lang}.
     */
    #[Test]
    public function execute_uses_correct_file_naming_convention(): void
    {
        $result = $this->action->execute($this->payload);

        foreach ($result["tracks"] as $domain => $domainTracks) {
            foreach ($domainTracks as $track) {
                $domainSlug = \Illuminate\Support\Str::slug($domain, "_");
                $typeSlug = \Illuminate\Support\Str::slug($track["type"], "_");
                $titleSlug = $result["title_slug"];

                $expectedBase = "{$domainSlug}-{$titleSlug}-{$typeSlug}-{$track["lang"]}";

                $jsonBasename = basename(
                    $track["content_path_json"],
                    "-content.json",
                );
                $this->assertSame($expectedBase, $jsonBasename);

                $mdBasename = basename(
                    $track["content_path_markdown"],
                    "-content.md",
                );
                $this->assertSame($expectedBase, $mdBasename);

                $descBasename = basename(
                    $track["content_description"],
                    "-description.md",
                );
                $this->assertSame($expectedBase, $descBasename);
            }
        }
    }

    /**
     * Verifies files are placed in a {typeSlug}-{lang} subdirectory under the domain slug.
     */
    #[Test]
    public function execute_files_in_type_lang_subdirectory(): void
    {
        $result = $this->action->execute($this->payload);

        foreach ($result["tracks"] as $domain => $domainTracks) {
            $domainSlug = \Illuminate\Support\Str::slug($domain, "_");

            foreach ($domainTracks as $trackKey => $track) {
                $dir = dirname($track["content_path_json"]);
                $expectedDir = "{$result["domain_dir"]}/{$domainSlug}/{$trackKey}";

                $this->assertSame(
                    $expectedDir,
                    $dir,
                    "File not in expected subdirectory for {$domain}/{$trackKey}",
                );
            }
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

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
