<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncAgentics\Actions;

use App\PncAgentics\Workflows\ResearchGatherer;
use RuntimeException;

/**
 * Gathers research content for each track in an agent structure.
 *
 * Iterates over the tracks defined by CreateAgentStructureAction, calls
 * ResearchGatherer::gather() once per track with the track's domain and
 * the shared research_dir, then stores the result under "research_text"
 * in each track entry.
 */
class ResearchAgentContentAction
{
    /**
     * @param ResearchGatherer $gatherer The research gatherer service.
     */
    public function __construct(protected ResearchGatherer $gatherer) {}

    /**
     * Execute the research gathering for all tracks in the structure.
     *
     * @param array{
     *     uuid: string,
     *     title: string,
     *     title_slug: string,
     *     domain_dir: string,
     *     assets_dir: string,
     *     research_dir: string,
     *     tracks: array<string, array{domain: string, ...}>
     * } $structure The agent structure from CreateAgentStructureAction.
     *
     * @return array The structure with "research_text" added to each track.
     *
     * @throws RuntimeException When "research_dir" is missing from the structure.
     */
    public function execute(array $structure): array
    {
        $researchDir = $structure["research_dir"] ?? null;
        $uuid = $structure["uuid"] ?? null;

        if ($researchDir === null) {
            throw new RuntimeException(
                "Agent structure is missing 'research_dir' key.",
            );
        }

        $result = $this->gatherer->gather($structure);

        file_put_contents(
            "{$researchDir}/research.json",
            json_encode(
                [["uuid" => $uuid], ["data" => $result]],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        return $result;
    }
}
