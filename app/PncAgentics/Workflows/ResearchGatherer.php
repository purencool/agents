<?php

namespace App\PncAgentics\Workflows;

use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Contracts\FileSaverInterface;

class ResearchGatherer
{
    public function __construct(
        private McpToolServiceInterface $mcp,
        private FileSaverInterface $saver,
    ) {}

    /**
     * Gather research data using MCP tools.
     * Saves all artifacts to the research directory.
     *
     * @param array  $payload         Must contain "title", optionally "url", "domain"
     * @param string $targetDirectory Absolute path to the research/ directory
     * @return string Combined research text to inject into refinement context
     */
    public function gather(array $payload, string $targetDirectory): string
    {
        $this->saver->setPath($targetDirectory);
        $findings = [];

        $url = $payload["url"] ?? null;
        $topic = $payload["title"] ?? "";
        $domain = $payload["domain"] ?? "";

        // If a URL is provided, scrape it
        if ($url) {
            $fetch = $this->mcp->callWithArtifacts(
                "web-fetch",
                $url,
                $targetDirectory,
                "source",
            );
            if ($fetch["success"]) {
                $findings[] =
                    "## Source: {$url}\n" . ($fetch["data"]["text"] ?? "");
            }
        }

        // Primary search (driver: search)
        $search = $this->mcp->call("search", $topic, "search");
        if ($search["success"]) {
            $this->saver->saveJson($search["data"], "search_primary");
            $findings[] =
                "## Search Results (Primary)\n" .
                json_encode(
                    array_slice($search["data"], 0, 5),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                );
        }

        // Secondary search (driver: search_two)
        $searchTwo = $this->mcp->call("search", $topic, "search_two");
        if ($searchTwo["success"]) {
            $this->saver->saveJson($searchTwo["data"], "search_secondary");
            $findings[] =
                "## Search Results (Secondary)\n" .
                json_encode(
                    array_slice($searchTwo["data"], 0, 5),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                );
        }

        // Search for related news (if domain is set)
        if ($domain) {
            $news = $this->mcp->call("news", "{$domain} {$topic}");
            if ($news["success"]) {
                $this->saver->saveJson($news["data"], "news_results");
                $findings[] =
                    "## Related News\n" .
                    json_encode(
                        array_slice($news["data"], 0, 3),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    );
            }
        }

        // Wikipedia summary (for context)
        $wiki = $this->mcp->call("wikipedia", $topic);
        if ($wiki["success"]) {
            $this->saver->saveJson($wiki["data"], "wikipedia");
            $findings[] =
                "## Wikipedia\n" .
                ($wiki["data"]["extract"] ?? json_encode($wiki["data"]));
        }

        // Save the combined research context
        $researchText = implode("\n\n---\n\n", $findings);
        $this->saver->saveMarkdown($researchText, "research_summary");

        return $researchText;
    }
}
