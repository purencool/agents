<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Pnc;

use App\PncAgentics\Contracts\AgentDataServiceInterface;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the pnc:agent:data:creation console command.
 *
 * Verifies that:
 *  - Invalid JSON input produces an error and FAILURE exit code.
 *  - Valid JSON is decoded and passed to AgentDataServiceInterface::executeCreationPipeline().
 *  - The pipeline's "structure" key is read for UUID and track count reporting.
 *  - Log::channel("daily")->info() is called with the completion message.
 *  - Missing "tracks" key defaults to a count of 0.
 *
 * @covers \App\Console\Commands\Pnc\DataCreation
 */
class DataCreationTest extends TestCase
{
    private AgentDataServiceInterface $agentData;

    /**
     * Standard payload matching the production input structure.
     *
     * @var array{title: string, description: string, categories: string, domains: array}
     */
    private array $payload = [
        "title" => "Can AI models be deployed on-premises and be affective?",
        "description" =>
            "[CONTEXT]:Can AI models be deployed on-premises and still function with the same performance, accuracy, and efficiency as cloud-hosted solutions?",
        "categories" => "AI, LLM's, Local Models",
        "domains" => [
            "example.com" => [
                "typeone" => [
                    "context" => "This for marketing",
                    "content_tracks" => [
                        "config" => [
                            "type" => "social",
                            "workflow" => "social_media",
                            "template" => "social",
                            "track" => "social_media",
                            "lang" => "en, ru",
                        ],
                    ],
                ],
                "typetwo" => [
                    "context" => "This for developer",
                    "content_tracks" => [
                        "config" => [
                            "type" => "blog_post",
                            "workflow" => "blog_post",
                            "template" => "blog_post",
                            "track" => "blog_post",
                            "lang" => "en",
                        ],
                    ],
                ],
                "typethree" => [
                    "context" => "This for sales",
                    "content_tracks" => [
                        "config" => [
                            "type" => "blog_post",
                            "workflow" => "blog_post",
                            "template" => "blog_post",
                            "track" => "blog_post",
                            "lang" => "en",
                        ],
                    ],
                ],
            ],
            "house.au" => [
                "sales" => [
                    "context" => "This for marketing",
                    "content_tracks" => [
                        "config" => [
                            "type" => "blog_post",
                            "workflow" => "blog_post",
                            "template" => "blog_post",
                            "track" => "blog_post",
                            "lang" => "en",
                        ],
                    ],
                ],
            ],
            "blog.com" => [
                "blog" => [
                    "context" => "This for marketing",
                    "content_tracks" => [
                        "config" => [
                            "type" => "social",
                            "workflow" => "social_media",
                            "template" => "social",
                            "track" => "blog_post",
                            "lang" => "en",
                        ],
                    ],
                ],
            ],
            "meeting.com" => [
                "meeting" => [
                    "context" => "This for marketing",
                    "content_tracks" => [
                        "config" => [
                            "type" => "blog_post",
                            "workflow" => "blog_post",
                            "template" => "blog_post",
                            "track" => "blog_post",
                            "lang" => "en",
                        ],
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->agentData = Mockery::mock(AgentDataServiceInterface::class);
        $this->app->instance(
            AgentDataServiceInterface::class,
            $this->agentData,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Invalid input
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Non-JSON input should produce an error message and FAILURE exit code.
     */
    #[Test]
    public function it_fails_on_invalid_json(): void
    {
        $this->artisan("pnc:agent:data:creation", ["data" => "not-json"])
            ->expectsOutputToContain("Invalid JSON")
            ->assertFailed();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Successful pipeline execution
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Valid JSON should be decoded, passed to executeCreationPipeline(),
     * and the output should report UUID and domain count.
     *
     * 4 domains in the payload → count($pipelineStructure["tracks"]) = 4.
     */
    #[Test]
    public function it_runs_pipeline_and_reports_success(): void
    {
        $json = json_encode($this->payload);

        $this->agentData
            ->shouldReceive("executeCreationPipeline")
            ->once()
            ->with($this->payload)
            ->andReturn([
                "structure" => [
                    "uuid" => "abc-123",
                    "title" => $this->payload["title"],
                    "tracks" => [
                        "example.com" => [
                            "typeone-en" => [],
                            "typeone-ru" => [],
                            "typetwo-en" => [],
                            "typethree-en" => [],
                        ],
                        "house.au" => ["sales-en" => []],
                        "blog.com" => ["blog-en" => []],
                        "meeting.com" => ["meeting-en" => []],
                    ],
                ],
            ]);

        Log::shouldReceive("channel")
            ->once()
            ->with("daily")
            ->andReturn(Mockery::mock()->shouldReceive("info")->once()->mock());

        $this->artisan("pnc:agent:data:creation", ["data" => $json])
            ->expectsOutputToContain(
                "Creating structure: Can AI models be deployed on-premises and be affective?",
            )
            ->expectsOutputToContain("UUID: abc-123  Tracks: 4")
            ->expectsOutputToContain("Done in")
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Payload passthrough
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The decoded payload (including all domains, contexts, categories)
     * should be passed unchanged to executeCreationPipeline().
     */
    #[Test]
    public function it_passes_full_payload_with_domains_to_pipeline(): void
    {
        $json = json_encode($this->payload);

        $this->agentData
            ->shouldReceive("executeCreationPipeline")
            ->once()
            ->withArgs(function (array $config): bool {
                return $config["title"] ===
                    "Can AI models be deployed on-premises and be affective?" &&
                    $config["categories"] === "AI, LLM's, Local Models" &&
                    $config["domains"]["example.com"]["typeone"]["context"] ===
                        "This for marketing" &&
                    $config["domains"]["example.com"]["typeone"][
                        "content_tracks"
                    ]["config"]["lang"] === "en, ru" &&
                    $config["domains"]["example.com"]["typetwo"][
                        "content_tracks"
                    ]["config"]["workflow"] === "blog_post" &&
                    $config["domains"]["house.au"]["sales"]["context"] ===
                        "This for marketing" &&
                    $config["domains"]["blog.com"]["blog"]["content_tracks"][
                        "config"
                    ]["track"] === "blog_post" &&
                    $config["domains"]["meeting.com"]["meeting"][
                        "content_tracks"
                    ]["config"]["type"] === "blog_post";
            })
            ->andReturn([
                "structure" => [
                    "uuid" => "def-456",
                    "tracks" => [
                        "example.com" => [],
                        "house.au" => [],
                        "blog.com" => [],
                        "meeting.com" => [],
                    ],
                ],
            ]);

        Log::shouldReceive("channel")
            ->once()
            ->with("daily")
            ->andReturn(Mockery::mock()->shouldReceive("info")->once()->mock());

        $this->artisan("pnc:agent:data:creation", ["data" => $json])
            ->expectsOutputToContain("UUID: def-456  Tracks: 4")
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Edge cases
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the pipeline returns a structure without a "tracks" key,
     * the track count should default to 0.
     */
    #[Test]
    public function it_handles_missing_tracks_key(): void
    {
        $json = json_encode($this->payload);

        $this->agentData
            ->shouldReceive("executeCreationPipeline")
            ->once()
            ->andReturn([
                "structure" => ["uuid" => "xyz-789"],
            ]);

        Log::shouldReceive("channel")
            ->once()
            ->with("daily")
            ->andReturn(Mockery::mock()->shouldReceive("info")->once()->mock());

        $this->artisan("pnc:agent:data:creation", ["data" => $json])
            ->expectsOutputToContain("UUID: xyz-789  Tracks: 0")
            ->assertSuccessful();
    }

    /**
     * When the pipeline return has no "structure" key, the console output
     * shows "N/A" and "0" (first assignment wins), but the Log line uses
     * the fallback ($structure itself) for the UUID.
     */
    #[Test]
    public function it_handles_missing_structure_key(): void
    {
        $json = json_encode($this->payload);

        $this->agentData
            ->shouldReceive("executeCreationPipeline")
            ->once()
            ->andReturn([
                "uuid" => "flat-uuid",
                "tracks" => ["a" => []],
            ]);

        Log::shouldReceive("channel")
            ->once()
            ->with("daily")
            ->andReturn(
                Mockery::mock()
                    ->shouldReceive("info")
                    ->once()
                    ->withArgs(function (
                        string $message,
                        array $context,
                    ): bool {
                        return $context["uuid"] === "flat-uuid";
                    })
                    ->mock(),
            );

        $this->artisan("pnc:agent:data:creation", ["data" => $json])
            ->expectsOutputToContain("UUID: N/A  Tracks: 0")
            ->assertSuccessful();
    }

    /**
     * The Log::channel("daily")->info() call should include the UUID and
     * title in its context array.
     */
    #[Test]
    public function it_logs_completion_with_uuid_and_title(): void
    {
        $json = json_encode($this->payload);

        $this->agentData
            ->shouldReceive("executeCreationPipeline")
            ->once()
            ->andReturn([
                "structure" => [
                    "uuid" => "log-uuid",
                    "tracks" => ["example.com" => []],
                ],
            ]);

        Log::shouldReceive("channel")
            ->once()
            ->with("daily")
            ->andReturn(
                Mockery::mock()
                    ->shouldReceive("info")
                    ->once()
                    ->withArgs(function (
                        string $message,
                        array $context,
                    ): bool {
                        return str_contains(
                            $message,
                            "BATCH CREATION COMPLETED",
                        ) &&
                            $context["uuid"] === "log-uuid" &&
                            $context["title"] === $this->payload["title"];
                    })
                    ->mock(),
            );

        $this->artisan("pnc:agent:data:creation", [
            "data" => $json,
        ])->assertSuccessful();
    }
}
