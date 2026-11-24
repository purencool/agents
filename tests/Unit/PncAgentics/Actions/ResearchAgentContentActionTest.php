<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Actions;

use App\PncAgentics\Actions\ResearchAgentContentAction;
use App\PncAgentics\Workflows\ResearchGatherer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see ResearchAgentContentAction}.
 *
 * Verifies that:
 *  - gather() is called exactly once with the full structure array.
 *  - The gatherer's return value is passed through as the method return.
 *  - research.json is written to research_dir with uuid and data.
 *  - A RuntimeException is thrown when research_dir is missing.
 *
 * @covers \App\PncAgentics\Actions\ResearchAgentContentAction
 */
class ResearchAgentContentActionTest extends TestCase
{
    private ResearchGatherer $gatherer;

    private ResearchAgentContentAction $action;

    private string $tempDir;

    /**
     * Mock structure matching CreateAgentStructureAction's documented return shape.
     *
     * @var array{
     *     uuid: string,
     *     title: string,
     *     title_slug: string,
     *     description: string,
     *     categories: string[],
     *     domain_dir: string,
     *     assets_dir: string,
     *     research_dir: string,
     *     tracks: array<string, array<string, array{
     *         uuid: string,
     *         title: string,
     *         categories: string[],
     *         workflow: string,
     *         lang: string,
     *         domain: string,
     *         context: string,
     *         type: string,
     *         template: string,
     *         track: string,
     *         track_type: string,
     *         content_json: string,
     *         content_path_json: string,
     *         content_html: string,
     *         content_path_html: string,
     *         content_markdown: string,
     *         content_path_markdown: string,
     *         content_description: string
     *     }>>
     * }
     */
    private array $structure;

    /**
     * Sets up a temp directory, builds the structure fixture, and mocks the gatherer.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . "/research_test_" . uniqid();
        mkdir("{$this->tempDir}/research", 0755, true);
        mkdir("{$this->tempDir}/assets", 0755, true);

        $this->structure = [
            "uuid" => "abc-123-def",
            "title" => "Can AI models be deployed on-premises?",
            "title_slug" => "can_ai_models_be_deployed_on_premises",
            "description" => "Test description for research gathering",
            "categories" => ["AI", "LLMs", "Local Models"],
            "domain_dir" => $this->tempDir,
            "assets_dir" => "{$this->tempDir}/assets",
            "research_dir" => "{$this->tempDir}/research",
            "tracks" => [
                "blog.com" => [
                    "blog-en" => [
                        "uuid" => "abc-123-def",
                        "title" => "Can AI models be deployed on-premises?",
                        "categories" => ["AI", "LLMs", "Local Models"],
                        "workflow" => "default",
                        "lang" => "en",
                        "domain" => "blog.com",
                        "context" => "This for marketing",
                        "type" => "blog",
                        "template" => "social",
                        "track" => "social_media",
                        "track_type" => "social",
                        "content_json" => "",
                        "content_path_json" => "{$this->tempDir}/blogcom/blog-en/blogcom-can_ai_models_be_deployed_on_premises-blog-en-content.json",
                        "content_html" => "",
                        "content_path_html" => "",
                        "content_markdown" => "",
                        "content_path_markdown" => "{$this->tempDir}/blogcom/blog-en/blogcom-can_ai_models_be_deployed_on_premises-blog-en-content.md",
                        "content_description" => "{$this->tempDir}/blogcom/blog-en/blogcom-can_ai_models_be_deployed_on_premises-blog-en-description.md",
                    ],
                ],
                "meeting.com" => [
                    "meeting-en" => [
                        "uuid" => "abc-123-def",
                        "title" => "Can AI models be deployed on-premises?",
                        "categories" => ["AI", "LLMs", "Local Models"],
                        "workflow" => "default",
                        "lang" => "en",
                        "domain" => "meeting.com",
                        "context" => "This for security",
                        "type" => "meeting",
                        "template" => "social",
                        "track" => "social_media",
                        "track_type" => "social",
                        "content_json" => "",
                        "content_path_json" => "{$this->tempDir}/meetingcom/meeting-en/meetingcom-can_ai_models_be_deployed_on_premises-meeting-en-content.json",
                        "content_html" => "",
                        "content_path_html" => "",
                        "content_markdown" => "",
                        "content_path_markdown" => "{$this->tempDir}/meetingcom/meeting-en/meetingcom-can_ai_models_be_deployed_on_premises-meeting-en-content.md",
                        "content_description" => "{$this->tempDir}/meetingcom/meeting-en/meetingcom-can_ai_models_be_deployed_on_premises-meeting-en-description.md",
                    ],
                ],
            ],
        ];

        $this->gatherer = \Mockery::mock(ResearchGatherer::class);
        $this->action = new ResearchAgentContentAction($this->gatherer);
    }

    /**
     * Closes Mockery and cleans up the temp directory after each test.
     */
    protected function tearDown(): void
    {
        \Mockery::close();
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    // ─── Gatherer invocation ─────────────────────────────────────────────

    /**
     * Verifies gather() is called exactly once with the full structure array.
     */
    #[Test]
    public function execute_calls_gatherer_once_with_full_structure(): void
    {
        $this->gatherer
            ->shouldReceive("gather")
            ->once()
            ->withArgs(function (array $structure): bool {
                return $structure["uuid"] === $this->structure["uuid"] &&
                    $structure["title"] === $this->structure["title"] &&
                    $structure["research_dir"] ===
                        $this->structure["research_dir"] &&
                    isset($structure["tracks"]);
            })
            ->andReturn(["findings" => "AI is effective"]);

        $this->action->execute($this->structure);
    }

    /**
     * Verifies the gatherer receives the exact structure array unchanged.
     */
    #[Test]
    public function execute_passes_structure_unchanged_to_gatherer(): void
    {
        $captured = null;

        $this->gatherer
            ->shouldReceive("gather")
            ->once()
            ->withArgs(function (array $structure) use (&$captured): bool {
                $captured = $structure;
                return true;
            })
            ->andReturn([]);

        $this->action->execute($this->structure);

        $this->assertSame($this->structure, $captured);
    }

    // ─── Return value ────────────────────────────────────────────────────

    /**
     * Verifies the return value is exactly what gather() returns.
     */
    #[Test]
    public function execute_returns_gatherer_result_directly(): void
    {
        $expected = ["key" => "value", "items" => [1, 2, 3]];

        $this->gatherer->shouldReceive("gather")->once()->andReturn($expected);

        $result = $this->action->execute($this->structure);

        $this->assertSame($expected, $result);
    }

    // ─── research.json persistence ───────────────────────────────────────

    /**
     * Verifies research.json is written to research_dir with the correct shape:
     * [0] => {"uuid": "..."}, [1] => {"data": <gatherer result>}
     */
    #[Test]
    public function execute_writes_research_json_to_research_dir(): void
    {
        $gathererResult = ["findings" => "AI is effective on-premises"];

        $this->gatherer
            ->shouldReceive("gather")
            ->once()
            ->andReturn($gathererResult);

        $this->action->execute($this->structure);

        $researchPath = "{$this->structure["research_dir"]}/research.json";
        $this->assertFileExists($researchPath);

        $data = json_decode((string) file_get_contents($researchPath), true);

        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $this->assertSame($this->structure["uuid"], $data[0]["uuid"]);
        $this->assertSame($gathererResult, $data[1]["data"]);
    }

    // ─── Error handling ──────────────────────────────────────────────────

    /**
     * Verifies a RuntimeException is thrown when "research_dir" is missing.
     */
    #[Test]
    public function execute_throws_when_research_dir_missing(): void
    {
        $structure = $this->structure;
        unset($structure["research_dir"]);

        $this->gatherer->shouldReceive("gather")->never();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing 'research_dir'");

        $this->action->execute($structure);
    }

    /**
     * Verifies gather() is NOT called when research_dir is missing.
     */
    #[Test]
    public function execute_does_not_call_gatherer_when_research_dir_missing(): void
    {
        $structure = $this->structure;
        unset($structure["research_dir"]);

        $this->gatherer->shouldReceive("gather")->never();

        $this->expectException(\RuntimeException::class);

        $this->action->execute($structure);
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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
