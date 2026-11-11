<?php

namespace App\PncAgentics\Workflows;

use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Contracts\FileSaverInterface;

/**
 * Gathers research data from multiple MCP tool sources and combines them
 * into a single markdown-formatted research context.
 *
 * Sources queried (in order):
 *   web-fetch  — only if a URL is provided in the payload
 *   search     — primary search (driver: "search")
 *   search     — secondary search (driver: "search_two")
 *   news       — only if a domain is provided in the payload
 *   wikipedia  — always
 *
 * All successful results are saved as JSON artifacts to the target directory
 * via {@see FileSaverInterface}, and the combined text is saved as
 * "research_summary.md".
 *
 * @see \App\PncAgentics\Contracts\McpToolServiceInterface
 * @see \App\PncAgentics\Contracts\FileSaverInterface
 */
class ResearchGatherer
{
    public function __construct(
        private readonly McpToolServiceInterface $mcp,
        private readonly FileSaverInterface $saver,
    ) {}

    /**
     * Gathers research data using MCP tools.
     *
     * Saves all artifacts to the research directory and returns the
     * combined research text for injection into downstream refinement context.
     *
     * @param array{
     *     title: string,
     *     url?: string,
     *     domain?: string
     * } $payload Must contain "title"; optionally "url" and "domain".
     *
     * @param string $targetDirectory Absolute path to the research/ directory.
     *
     * @return string Combined research text (markdown sections separated by "---").
     *                Empty string if no source returns a successful result.
     */
    public function gather(array $payload, string $targetDirectory): string
    {
        $this->saver->setPath($targetDirectory);
        $findings = [];

        $url = $payload["url"] ?? null;
        $topic = $payload["title"] ?? "";
        $domain = $payload["domain"] ?? "";

        // Web fetch (conditional on URL)
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

        // Primary search
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

        // Secondary search
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

        // News (conditional on domain)
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

        // Wikipedia
        $wiki = $this->mcp->call("wikipedia", $topic);
        if ($wiki["success"]) {
            $this->saver->saveJson($wiki["data"], "wikipedia");
            $findings[] =
                "## Wikipedia\n" .
                ($wiki["data"]["extract"] ?? json_encode($wiki["data"]));
        }

        // Save combined research context
        $researchText = implode("\n\n---\n\n", $findings);
        $this->saver->saveMarkdown($researchText, "research_summary");

        return $researchText;
    }
}
