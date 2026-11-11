<?php

namespace App\PncServices\Agent\Actions;

use App\PncAgentics\Workflows\ResearchGatherer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Runs research content generation for each track in the structure.
 *
 * Iterates over the tracks returned by {@see CreateAgentStructureAction}
 * and calls {@see ResearchGatherer::gather()} for each, storing the
 * combined research text alongside the track metadata.
 *
 * @see \App\PncAgentics\Workflows\ResearchGatherer
 */
class ResearchAgentContentAction
{
    public function __construct(private readonly ResearchGatherer $gatherer) {}

    /**
     * Executes research gathering for every track in the structure.
     *
     * For each track, builds a payload from the track's domain and the
     * structure's title, then calls the gatherer with the research directory.
     * @todo UpdateAgentStructureAction::execute().
     *
     * @param array[
     *     uuid: string,
     *     domain_dir: string,
     *     assets_dir: string,
     *     research_dir: string,
     *     title: string,
     *     title_slug: string,
     *     tracks: array<string, array[
     *         domain: string,
     *         type: string,
     *         template: string,
     *         track: string,
     *         track_type: string,
     *         workflow: string,
     *         lang: string,
     *         content_json: string,
     *         content_md: string,
     *         description_md: string
     *     ],
     * ] $structure Return value from {@see CreateAgentStructureAction::execute()}.
     *
     * @return array{
     *     uuid: string,
     *     domain_dir: string,
     *     assets_dir: string,
     *     research_dir: string,
     *     title: string,
     *     title_slug: string,
     *     tracks: array<string, array{
     *         domain: string,
     *         type: string,
     *         template: string,
     *         track: string,
     *         track_type: string,
     *         workflow: string,
     *         lang: string,
     *         content_json: string,
     *         content_md: string,
     *         description_md: string,
     *         research_text: string
     *     }>
     * }
     *
     * @throws RuntimeException If the structure is missing the "research_dir" key.
     */
    public function execute(array $structure): array
    {
        Log::channel("daily")->info(
            " [RESEARCH STARTED] tracks=" . count($structure["tracks"]),
            [
                "uuid" => $structure["uuid"],
                "title" => $structure["title"],
                "tracks" => array_keys($structure["tracks"]),
            ],
        );

        $researchDir = $structure["research_dir"] ?? null;
        if ($researchDir === null) {
            throw new RuntimeException(
                "Structure is missing 'research_dir' key.",
            );
        }

        foreach ($structure["tracks"] as $key => $track) {
            $payload = [
                "title" => $structure["title"],
                "domain" => $track["domain"],
            ];

            $structure["tracks"][$key][
                "research_text"
            ] = $this->gatherer->gather($payload, $researchDir);
        }

        return $structure;
    }
}
