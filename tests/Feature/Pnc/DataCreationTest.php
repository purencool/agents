<?php

namespace Tests\Unit\Pnc\Commands;

use App\Console\Commands\Pnc\DataCreation;
use App\PncServices\Contracts\AgentDataInterface;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataCreationTest extends TestCase
{
    private AgentDataInterface $agentData;

    private array $payload = [
        "title" =>
            "Can AI models be deployed on-premises and still function with the same performance, accuracy, and efficiency as cloud-hosted solutions?",
        "description" =>
            "[CONTEXT]:Can AI models be deployed on-premises and still function with the same performance, accuracy, and efficiency as cloud-hosted solutions?",
        "domains" => [
            "blog.com" => [
                "blog" => [
                    "content_tracks" => [
                        "config" => [
                            "type" => "social",
                            "workflow" => "default",
                            "template" => "social",
                            "track" => "social_media",
                            "lang" => "en",
                        ],
                    ],
                ],
            ],
            "meeting.com" => [
                "meeting" => [
                    "content_tracks" => [
                        "config" => [
                            "type" => "social",
                            "workflow" => "default",
                            "template" => "social",
                            "track" => "social_media",
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

        $this->agentData = \Mockery::mock(AgentDataInterface::class);
        $this->app->instance(AgentDataInterface::class, $this->agentData);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_fails_on_invalid_json(): void
    {
        $this->artisan("pnc:agent:data:creation", ["data" => "not-json"])
            ->expectsOutputToContain("Invalid JSON")
            ->assertFailed();
    }

    #[Test]
    public function it_runs_pipeline_and_reports_success(): void
    {
        $json = json_encode($this->payload);

        $this->agentData
            ->shouldReceive("executeFullCreationPipeline")
            ->once()
            ->with($this->payload)
            ->andReturn([
                "structure" => [
                    "uuid" => "abc-123",
                    "tracks" => ["t1", "t2", "t3"],
                ],
            ]);

        Log::shouldReceive("channel")
            ->once()
            ->with("daily")
            ->andReturn(
                \Mockery::mock()->shouldReceive("info")->once()->mock(),
            );

        $this->artisan("pnc:agent:data:creation", ["data" => $json])
            ->expectsOutputToContain(
                "Creating structure: Can AI models be deployed on-premises",
            )
            ->expectsOutputToContain("UUID: abc-123  Tracks: 3")
            ->expectsOutputToContain("Done in")
            ->assertSuccessful();
    }

    #[Test]
    public function it_passes_full_payload_with_domains_to_pipeline(): void
    {
        $json = json_encode($this->payload);

        $this->agentData
            ->shouldReceive("executeFullCreationPipeline")
            ->once()
            ->withArgs(function (array $config) {
                return isset(
                    $config["domains"]["blog.com"]["blog"]["content_tracks"][
                        "config"
                    ],
                ) &&
                    $config["domains"]["blog.com"]["blog"]["content_tracks"][
                        "config"
                    ]["track"] === "social_media" &&
                    isset(
                        $config["domains"]["meeting.com"]["meeting"][
                            "content_tracks"
                        ]["config"],
                    ) &&
                    $config["domains"]["meeting.com"]["meeting"][
                        "content_tracks"
                    ]["config"]["lang"] === "en";
            })
            ->andReturn([
                "structure" => [
                    "uuid" => "def-456",
                    "tracks" => ["a", "b"],
                ],
            ]);

        Log::shouldReceive("channel")
            ->once()
            ->with("daily")
            ->andReturn(
                \Mockery::mock()->shouldReceive("info")->once()->mock(),
            );

        $this->artisan("pnc:agent:data:creation", ["data" => $json])
            ->expectsOutputToContain("UUID: def-456  Tracks: 2")
            ->assertSuccessful();
    }

    #[Test]
    public function it_handles_missing_tracks_key(): void
    {
        $json = json_encode($this->payload);

        $this->agentData
            ->shouldReceive("executeFullCreationPipeline")
            ->once()
            ->andReturn([
                "structure" => ["uuid" => "xyz-789"],
            ]);

        Log::shouldReceive("channel")
            ->once()
            ->with("daily")
            ->andReturn(
                \Mockery::mock()->shouldReceive("info")->once()->mock(),
            );

        $this->artisan("pnc:agent:data:creation", ["data" => $json])
            ->expectsOutputToContain("UUID: xyz-789  Tracks: 0")
            ->assertSuccessful();
    }
}
