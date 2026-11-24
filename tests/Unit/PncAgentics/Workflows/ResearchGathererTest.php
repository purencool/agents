<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Workflows;

use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Workflows\ResearchGatherer;
use App\PncServices\Contracts\ContentPersistenceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ResearchGatherer}.
 *
 * Verifies that:
 *  - MCP tools are called with the correct arguments and in the correct order.
 *  - web-fetch is called only when a URL is present in the payload.
 *  - Primary and secondary searches are always called.
 *  - News is always called (not conditional on domain).
 *  - Wikipedia is called once per category in the payload's "categories" array.
 *  - Successful results are saved via ContentPersistenceInterface.
 *  - Failed results are skipped (no section added to findings).
 *  - The return value is an array of markdown section strings.
 *  - saveMarkdown is called with the combined text and "research_summary".
 *
 * @covers \App\PncAgentics\Workflows\ResearchGatherer
 */
class ResearchGathererTest extends TestCase
{
    private McpToolServiceInterface $mcp;

    private ContentPersistenceInterface $persistence;

    private ResearchGatherer $gatherer;

    private string $researchDir = "/tmp/research_test_abc";

    /**
     * Standard payload with all fields including URL and categories.
     *
     * @var array{title: string, url: string, research_dir: string, categories: string[]}
     */
    private array $fullPayload = [
        "title" => "Can AI models be deployed on-premises?",
        "url" => "https://example.com/article",
        "research_dir" => "/tmp/research_test_abc",
        "categories" => ["AI", "LLMs"],
    ];

    /**
     * Minimal payload (title + research_dir + single category).
     *
     * @var array{title: string, research_dir: string, categories: string[]}
     */
    private array $minimalPayload = [
        "title" => "Can AI models be deployed on-premises?",
        "research_dir" => "/tmp/research_test_abc",
        "categories" => ["AI"],
    ];

    /**
     * Set up mocks and instantiate the gatherer.
     */
    protected function setUp(): void
    {
        $this->mcp = \Mockery::mock(McpToolServiceInterface::class);
        $this->persistence = \Mockery::mock(ContentPersistenceInterface::class);
        $this->gatherer = new ResearchGatherer($this->mcp, $this->persistence);
    }

    /**
     * Reset Mockery after each test.
     */
    protected function tearDown(): void
    {
        \Mockery::close();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Path setup
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that persistence->setPath() is called with the payload's research_dir.
     */
    #[Test]
    public function gather_sets_persistence_path_to_research_dir(): void
    {
        $this->persistence
            ->shouldReceive("setPath")
            ->once()
            ->with($this->minimalPayload["research_dir"]);

        $this->mcp->shouldReceive("call")->andReturn(["success" => false]);
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $this->gatherer->gather($this->minimalPayload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Web fetch (conditional on URL)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that web-fetch is called when a URL is present in the payload.
     */
    #[Test]
    public function gather_calls_web_fetch_when_url_provided(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

        $this->mcp
            ->shouldReceive("callWithArtifacts")
            ->once()
            ->with(
                "web-fetch",
                $this->fullPayload["url"],
                $this->fullPayload["research_dir"],
                "source",
            )
            ->andReturn([
                "success" => true,
                "data" => ["text" => "scraped content"],
            ]);

        $this->mcp->shouldReceive("call")->andReturn(["success" => false]);
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $result = $this->gatherer->gather($this->fullPayload);

        $this->assertIsArray($result);
        $this->assertStringContainsString(
            "## Source: {$this->fullPayload["url"]}",
            $result[0],
        );
        $this->assertStringContainsString("scraped content", $result[0]);
    }

    /**
     * Verifies that web-fetch is NOT called when no URL is provided.
     */
    #[Test]
    public function gather_does_not_call_web_fetch_without_url(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

        $this->mcp->shouldNotReceive("callWithArtifacts");
        $this->mcp->shouldReceive("call")->andReturn(["success" => false]);
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $this->gatherer->gather($this->minimalPayload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Search (primary + secondary)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that primary and secondary searches are always called
     * with the correct driver names.
     */
    #[Test]
    public function gather_calls_primary_and_secondary_search(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

        $topic = $this->minimalPayload["title"];

        $this->mcp
            ->shouldReceive("call")
            ->once()
            ->with("search", $topic, "search")
            ->andReturn([
                "success" => true,
                "data" => [["title" => "Result 1"]],
            ]);

        $this->mcp
            ->shouldReceive("call")
            ->once()
            ->with("search", $topic, "search_two")
            ->andReturn([
                "success" => true,
                "data" => [["title" => "Result 2"]],
            ]);

        // News (always called)
        $this->mcp
            ->shouldReceive("call")
            ->once()
            ->with("news", $topic)
            ->andReturn(["success" => false]);

        // Wikipedia (one per category)
        $this->mcp
            ->shouldReceive("call")
            ->once()
            ->with("wikipedia", "AI")
            ->andReturn(["success" => false]);

        $this->persistence->shouldReceive("saveJsonTo")->twice();
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $result = $this->gatherer->gather($this->minimalPayload);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertStringContainsString(
            "## Search Results (Primary)",
            $result[0],
        );
        $this->assertStringContainsString(
            "## Search Results (Secondary)",
            $result[1],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // News (always called)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that news is always called with just the topic (not domain + topic).
     */
    #[Test]
    public function gather_calls_news_with_topic_only(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

        $this->mcp
            ->shouldReceive("callWithArtifacts")
            ->andReturn(["success" => false]);

        $this->mcp
            ->shouldReceive("call")
            ->with("search", \Mockery::any(), \Mockery::any())
            ->andReturn(["success" => false]);

        $this->mcp
            ->shouldReceive("call")
            ->once()
            ->with("news", "Can AI models be deployed on-premises?")
            ->andReturn([
                "success" => true,
                "data" => [["headline" => "News Item"]],
            ]);

        $this->mcp
            ->shouldReceive("call")
            ->with("wikipedia", \Mockery::any())
            ->andReturn(["success" => false]);

        $this->persistence
            ->shouldReceive("saveJsonTo")
            ->once()
            ->with("news_results", [["headline" => "News Item"]]);
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $result = $this->gatherer->gather($this->fullPayload);

        $this->assertIsArray($result);
        $this->assertStringContainsString("## Related News", $result[0]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Wikipedia (one call per category)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that wikipedia is called once per category in the payload.
     */
    #[Test]
    public function gather_calls_wikipedia_once_per_category(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

        $this->mcp
            ->shouldReceive("callWithArtifacts")
            ->andReturn(["success" => false]);

        $this->mcp
            ->shouldReceive("call")
            ->with("search", \Mockery::any(), \Mockery::any())
            ->andReturn(["success" => false]);

        $this->mcp
            ->shouldReceive("call")
            ->with("news", \Mockery::any())
            ->andReturn(["success" => false]);

        // Two categories → two wikipedia calls
        $this->mcp
            ->shouldReceive("call")
            ->once()
            ->with("wikipedia", "AI")
            ->andReturn([
                "success" => true,
                "data" => ["extract" => "AI is a field of computer science..."],
            ]);

        $this->mcp
            ->shouldReceive("call")
            ->once()
            ->with("wikipedia", "LLMs")
            ->andReturn([
                "success" => true,
                "data" => ["extract" => "LLMs are large language models..."],
            ]);

        $this->persistence
            ->shouldReceive("saveJsonTo")
            ->once()
            ->with("wikipedia", [
                ["extract" => "AI is a field of computer science..."],
                ["extract" => "LLMs are large language models..."],
            ]);
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $result = $this->gatherer->gather($this->fullPayload);

        $this->assertIsArray($result);
        // Two wikipedia sections
        $this->assertCount(2, $result);
        $this->assertStringContainsString("## Wikipedia", $result[0]);
        $this->assertStringContainsString(
            "AI is a field of computer science...",
            $result[0],
        );
        $this->assertStringContainsString("## Wikipedia", $result[1]);
        $this->assertStringContainsString(
            "LLMs are large language models...",
            $result[1],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Failure handling
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that when all MCP calls fail, the result is an empty array
     * and saveMarkdown receives an empty string.
     */
    #[Test]
    public function gather_returns_empty_array_when_all_calls_fail(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

        $this->mcp
            ->shouldReceive("callWithArtifacts")
            ->andReturn(["success" => false]);
        $this->mcp->shouldReceive("call")->andReturn(["success" => false]);
        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->with("", "research_summary");

        $result = $this->gatherer->gather($this->fullPayload);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Persistence verification
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that saveJsonTo is called with the correct filename and data
     * for each successful source.
     */
    #[Test]
    public function gather_saves_json_with_correct_filenames(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

        $this->mcp
            ->shouldReceive("callWithArtifacts")
            ->andReturn(["success" => false]);

        $this->mcp
            ->shouldReceive("call")
            ->with("search", \Mockery::any(), "search")
            ->andReturn(["success" => true, "data" => [["t" => "1"]]]);
        $this->mcp
            ->shouldReceive("call")
            ->with("search", \Mockery::any(), "search_two")
            ->andReturn(["success" => true, "data" => [["t" => "2"]]]);
        $this->mcp
            ->shouldReceive("call")
            ->with("news", \Mockery::any())
            ->andReturn(["success" => true, "data" => [["h" => "news"]]]);
        $this->mcp
            ->shouldReceive("call")
            ->with("wikipedia", \Mockery::any())
            ->andReturn([
                "success" => true,
                "data" => ["extract" => "wiki text"],
            ]);

        $this->persistence
            ->shouldReceive("saveJsonTo")
            ->once()
            ->with("search_primary", [["t" => "1"]]);
        $this->persistence
            ->shouldReceive("saveJsonTo")
            ->once()
            ->with("search_secondary", [["t" => "2"]]);
        $this->persistence
            ->shouldReceive("saveJsonTo")
            ->once()
            ->with("news_results", [["h" => "news"]]);
        $this->persistence
            ->shouldReceive("saveJsonTo")
            ->once()
            ->with("wikipedia", [["extract" => "wiki text"]]);

        $this->persistence->shouldReceive("saveMarkdown")->once();

        $this->gatherer->gather($this->minimalPayload);
    }

    /**
     * Verifies that saveMarkdown is called with the combined text (joined by
     * "\n\n---\n\n") and "research_summary" filename.
     */
    #[Test]
    public function gather_saves_combined_markdown_as_research_summary(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

        $this->mcp
            ->shouldReceive("callWithArtifacts")
            ->andReturn(["success" => false]);

        $this->mcp
            ->shouldReceive("call")
            ->with("search", \Mockery::any(), "search")
            ->andReturn([
                "success" => true,
                "data" => [["title" => "Only Result"]],
            ]);
        $this->mcp
            ->shouldReceive("call")
            ->with("search", \Mockery::any(), "search_two")
            ->andReturn(["success" => false]);
        $this->mcp
            ->shouldReceive("call")
            ->with("news", \Mockery::any())
            ->andReturn(["success" => false]);
        $this->mcp
            ->shouldReceive("call")
            ->with("wikipedia", \Mockery::any())
            ->andReturn([
                "success" => true,
                "data" => ["extract" => "Wiki content"],
            ]);

        $this->persistence->shouldReceive("saveJsonTo")->twice();

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->withArgs(function (string $text, string $filename): bool {
                return $filename === "research_summary" &&
                    str_contains($text, "## Search Results (Primary)") &&
                    str_contains($text, "## Wikipedia") &&
                    str_contains($text, "Wiki content") &&
                    !str_contains($text, "## Search Results (Secondary)") &&
                    str_contains($text, "\n\n---\n\n");
            });

        $this->gatherer->gather($this->minimalPayload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Output formatting
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that array_slice limits search results to 5 items in output.
     */
    #[Test]
    public function gather_limits_search_results_to_five_in_output(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

        $tenResults = array_map(
            fn($i) => ["title" => "Result {$i}"],
            range(1, 10),
        );

        $this->mcp
            ->shouldReceive("call")
            ->with("search", \Mockery::any(), "search")
            ->andReturn(["success" => true, "data" => $tenResults]);
        $this->mcp
            ->shouldReceive("call")
            ->with("search", \Mockery::any(), "search_two")
            ->andReturn(["success" => false]);
        $this->mcp
            ->shouldReceive("call")
            ->with("news", \Mockery::any())
            ->andReturn(["success" => false]);
        $this->mcp
            ->shouldReceive("call")
            ->with("wikipedia", \Mockery::any())
            ->andReturn(["success" => false]);

        $this->persistence->shouldReceive("saveJsonTo")->once();
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $result = $this->gatherer->gather($this->minimalPayload);

        $this->assertIsArray($result);
        $this->assertStringContainsString("Result 5", $result[0]);
        $this->assertStringNotContainsString("Result 6", $result[0]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Return type
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that gather() always returns an array.
     */
    #[Test]
    public function gather_always_returns_array(): void
    {
        $this->persistence->shouldReceive("setPath")->once();
        $this->mcp->shouldReceive("call")->andReturn(["success" => false]);
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $result = $this->gatherer->gather($this->minimalPayload);

        $this->assertIsArray($result);
    }
}
