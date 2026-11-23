<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Workflows;

use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncServices\Contracts\ContentPersistenceInterface;

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
 * via {@see ContentPersistenceInterface}, and the combined text is saved as
 * "research_summary.md".
 *
 * @see \App\PncAgentics\Contracts\McpToolServiceInterface
 * @see \App\PncServices\Contracts\ContentPersistenceInterface
 */
class ResearchGatherer
{
    /**
     * @param McpToolServiceInterface   $mcp          Service for invoking MCP tools.
     * @param ContentPersistenceInterface $persistence  Service for persisting artifact files.
     */
    public function __construct(
        private readonly McpToolServiceInterface $mcp,
        private readonly ContentPersistenceInterface $persistence,
    ) {}

    /**
     * Gathers research data using MCP tools.
     *
     * Saves all artifacts to the research directory and returns the
     * combined research text for injection into downstream refinement context.
     *
     * @param $payload Must contain "title"; optionally "url" and "domain".
     *
     * @return array Combined research text (markdown sections separated by "---").
     *                Empty string if no source returns a successful result.
     */
    public function gather(array $payload): array
    {
        $this->persistence->setPath($payload["research_dir"]);
        $findings = [];

        $url = $payload["url"] ?? null;
        $topic = $payload["title"] ?? "";
        $categories = $payload["categories"] ?? "";

        // Web fetch (conditional on URL)
        if ($url) {
            $fetch = $this->mcp->callWithArtifacts(
                "web-fetch",
                $url,
                $payload["research_dir"],
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
            $this->persistence->saveJsonTo("search_primary", $search["data"]);
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
            $this->persistence->saveJsonTo(
                "search_secondary",
                $searchTwo["data"],
            );
            $findings[] =
                "## Search Results (Secondary)\n" .
                json_encode(
                    array_slice($searchTwo["data"], 0, 5),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                );
        }

        // News (conditional on domain)
        $news = $this->mcp->call("news", "{$topic}");
        if ($news["success"]) {
            $this->persistence->saveJsonTo("news_results", $news["data"]);
            $findings[] =
                "## Related News\n" .
                json_encode(
                    array_slice($news["data"], 0, 3),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                );
        }

        // Wikipedia
        $wiki_save = [];
        foreach ($categories as $category) {
            $wiki = $this->mcp->call("wikipedia", $category);
            if ($wiki["success"]) {
                $wiki_save[] = $wiki["data"];
                $findings[] =
                    "## Wikipedia\n" .
                    ($wiki["data"]["extract"] ?? json_encode($wiki["data"]));
            }
        }

        if ($wiki["success"]) {
            $this->persistence->saveJsonTo("wikipedia", $wiki_save);
        }

        // Save combined research context
        $researchText = implode("\n\n---\n\n", $findings);
        $this->persistence->saveMarkdown($researchText, "research_summary");

        return $findings;
    }
}
