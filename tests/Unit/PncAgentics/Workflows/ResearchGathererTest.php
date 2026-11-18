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
 * Verifies that MCP tools are called in the correct order with the correct
 * arguments, that successful results are saved via ContentPersistenceInterface,
 * that failed results are skipped, and that the combined output contains the
 * expected markdown sections.
 *
 * @covers \App\PncAgentics\Workflows\ResearchGatherer
 */
class ResearchGathererTest extends TestCase
{
    private McpToolServiceInterface $mcp;

    private ContentPersistenceInterface $persistence;

    private ResearchGatherer $gatherer;

    private string $targetDir = "/tmp/research_test_abc";

    /**
     * Standard payload with all optional fields.
     *
     * @var array{title: string, url: string, domain: string}
     */
    private array $fullPayload = [
        "title" => "Can AI models be deployed on-premises?",
        "url" => "https://example.com/article",
        "domain" => "blog.com",
    ];

    /**
     * Minimal payload (title only).
     *
     * @var array{title: string}
     */
    private array $minimalPayload = [
        "title" => "Can AI models be deployed on-premises?",
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
     * Verifies that persistence->setPath() is called with the target directory.
     */
    #[Test]
    public function gather_sets_persistence_path_to_target_directory(): void
    {
        $this->persistence
            ->shouldReceive("setPath")
            ->once()
            ->with($this->targetDir);
        $this->mcp->shouldReceive("call")->andReturn(["success" => false]);
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $this->gatherer->gather($this->minimalPayload, $this->targetDir);
        $this->addToAssertionCount(1);
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
                $this->targetDir,
                "source",
            )
            ->andReturn([
                "success" => true,
                "data" => ["text" => "scraped content"],
            ]);

        $this->mcp->shouldReceive("call")->andReturn(["success" => false]);
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $result = $this->gatherer->gather($this->fullPayload, $this->targetDir);

        $this->assertStringContainsString(
            "## Source: {$this->fullPayload["url"]}",
            $result,
        );
        $this->assertStringContainsString("scraped content", $result);
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

        $this->gatherer->gather($this->minimalPayload, $this->targetDir);
        $this->addToAssertionCount(1);
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

        $this->mcp
            ->shouldReceive("call")
            ->once()
            ->with("wikipedia", $topic)
            ->andReturn(["success" => false]);

        $this->persistence->shouldReceive("saveJsonTo")->twice();
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $result = $this->gatherer->gather(
            $this->minimalPayload,
            $this->targetDir,
        );

        $this->assertStringContainsString(
            "## Search Results (Primary)",
            $result,
        );
        $this->assertStringContainsString(
            "## Search Results (Secondary)",
            $result,
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // News (conditional on domain)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that news search is called only when a domain is provided.
     */
    #[Test]
    public function gather_calls_news_when_domain_provided(): void
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
            ->with("news", "blog.com Can AI models be deployed on-premises?")
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

        $result = $this->gatherer->gather($this->fullPayload, $this->targetDir);

        $this->assertStringContainsString("## Related News", $result);
    }

    /**
     * Verifies that news search is NOT called when no domain is provided.
     */
    #[Test]
    public function gather_does_not_call_news_without_domain(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

        $this->mcp
            ->shouldReceive("call")
            ->with("news", \Mockery::any())
            ->never();

        $this->mcp->shouldReceive("call")->andReturn(["success" => false]);
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $this->gatherer->gather($this->minimalPayload, $this->targetDir);
        $this->addToAssertionCount(1);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Wikipedia
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that wikipedia is always called and its extract is included.
     */
    #[Test]
    public function gather_calls_wikipedia_and_includes_extract(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

        $this->mcp
            ->shouldReceive("call")
            ->with("search", \Mockery::any(), \Mockery::any())
            ->andReturn(["success" => false]);
        $this->mcp
            ->shouldReceive("call")
            ->with("news", \Mockery::any())
            ->andReturn(["success" => false]);

        $this->mcp
            ->shouldReceive("call")
            ->once()
            ->with("wikipedia", $this->minimalPayload["title"])
            ->andReturn([
                "success" => true,
                "data" => ["extract" => "AI is a field of computer science..."],
            ]);

        $this->persistence
            ->shouldReceive("saveJsonTo")
            ->once()
            ->with("wikipedia", [
                "extract" => "AI is a field of computer science...",
            ]);
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $result = $this->gatherer->gather(
            $this->minimalPayload,
            $this->targetDir,
        );

        $this->assertStringContainsString("## Wikipedia", $result);
        $this->assertStringContainsString(
            "AI is a field of computer science...",
            $result,
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Failure handling
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verifies that failed MCP calls are silently skipped (no section in output).
     */
    #[Test]
    public function gather_skips_failed_calls_in_output(): void
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

        $result = $this->gatherer->gather($this->fullPayload, $this->targetDir);

        $this->assertSame("", $result);
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
            ->with("wikipedia", ["extract" => "wiki text"]);

        $this->persistence->shouldReceive("saveMarkdown")->once();

        $this->gatherer->gather($this->fullPayload, $this->targetDir);
        $this->addToAssertionCount(1);
    }

    /**
     * Verifies that saveMarkdown is called with the combined text and
     * "research_summary" filename.
     */
    #[Test]
    public function gather_saves_combined_markdown_as_research_summary(): void
    {
        $this->persistence->shouldReceive("setPath")->once();

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
            ->with("wikipedia", \Mockery::any())
            ->andReturn([
                "success" => true,
                "data" => ["extract" => "Wiki content"],
            ]);

        $this->persistence->shouldReceive("saveJsonTo")->twice();

        $this->persistence
            ->shouldReceive("saveMarkdown")
            ->once()
            ->withArgs(function (string $text, string $filename) {
                return $filename === "research_summary" &&
                    str_contains($text, "## Search Results (Primary)") &&
                    str_contains($text, "## Wikipedia") &&
                    str_contains($text, "Wiki content") &&
                    !str_contains($text, "## Search Results (Secondary)");
            });

        $this->gatherer->gather($this->minimalPayload, $this->targetDir);
        $this->addToAssertionCount(1);
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
            ->with("wikipedia", \Mockery::any())
            ->andReturn(["success" => false]);

        $this->persistence->shouldReceive("saveJsonTo")->once();
        $this->persistence->shouldReceive("saveMarkdown")->once();

        $result = $this->gatherer->gather(
            $this->minimalPayload,
            $this->targetDir,
        );

        $this->assertStringContainsString("Result 5", $result);
        $this->assertStringNotContainsString("Result 6", $result);
    }
}
