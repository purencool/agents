<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace Tests\Feature\Console\Commands\Pnc;

use App\PncTemplates\Contracts\TemplateTransformerInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for {@see \App\Console\Commands\Pnc\DataUpdate}.
 *
 * Verifies:
 *  - FAILURE when the JSON payload is invalid or missing required keys.
 *  - SUCCESS when all tracks transform without error.
 *  - Per-track skip (warning) when the transformer throws InvalidArgumentException.
 *  - Hard FAILURE when the transformer throws a generic Exception.
 *  - Correct output formatting (domain, title, separators, duration).
 *  - Log output to the "daily" channel on completion.
 */
class DataUpdateCommandTest extends TestCase
{
    /**
     * Mock for the template transformer.
     */
    private \Mockery\MockInterface $transformer;

    /**
     * Set up mocks and container bindings.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->transformer = Mockery::mock(TemplateTransformerInterface::class);
        $this->app->instance(
            TemplateTransformerInterface::class,
            $this->transformer,
        );
    }

    /**
     * Clean up Mockery after each test.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Input validation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Invalid JSON should produce an error and return FAILURE.
     */
    #[Test]
    public function handle_failsOnInvalidJson(): void
    {
        $this->artisan("pnc:agent:data:update", ["data" => "{not valid json"])
            ->expectsOutputToContain("Invalid JSON")
            ->assertFailed();
    }

    /**
     * Missing "domain" key should produce an error and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenDomainMissing(): void
    {
        $this->artisan("pnc:agent:data:update", [
            "data" => json_encode(["title" => "T", "contentTracks" => []]),
        ])
            ->expectsOutputToContain(
                'must contain "domain", "title", and "contentTracks"',
            )
            ->assertFailed();
    }

    /**
     * Missing "title" key should produce an error and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenTitleMissing(): void
    {
        $this->artisan("pnc:agent:data:update", [
            "data" => json_encode(["domain" => "x.com", "contentTracks" => []]),
        ])
            ->expectsOutputToContain(
                'must contain "domain", "title", and "contentTracks"',
            )
            ->assertFailed();
    }

    /**
     * Missing "contentTracks" key should produce an error and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenContentTracksMissing(): void
    {
        $this->artisan("pnc:agent:data:update", [
            "data" => json_encode(["domain" => "x.com", "title" => "T"]),
        ])
            ->expectsOutputToContain(
                'must contain "domain", "title", and "contentTracks"',
            )
            ->assertFailed();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Happy path: all tracks succeed
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When all tracks transform successfully, the command should print
     * success messages for each and return SUCCESS.
     */
    #[Test]
    public function handle_succeedsWhenAllTracksTransform(): void
    {
        $this->transformer
            ->shouldReceive("transform")
            ->twice()
            ->andReturn([
                "target_directory" => "/out/track",
                "extracted_fields" => [],
                "html_elements" => [],
            ]);

        $this->artisan("pnc:agent:data:update", [
            "data" => json_encode([
                "domain" => "test.com",
                "title" => "Test Article",
                "contentTracks" => [
                    "blog" => "blog_template",
                    "social" => "social_template",
                ],
            ]),
        ])
            ->expectsOutputToContain("Initiating content update pipeline")
            ->expectsOutputToContain("Domain: test.com")
            ->expectsOutputToContain("Title:  Test Article")
            ->expectsOutputToContain("Processing update for track [blog]")
            ->expectsOutputToContain("Processing update for track [social]")
            ->expectsOutputToContain("✓ Updated: /out/track")
            ->expectsOutputToContain("Batch update pipeline completed in")
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Per-track error: InvalidArgumentException (skip)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the transformer throws InvalidArgumentException for one track,
     * the command should warn, skip that track, continue with the rest,
     * and still return SUCCESS.
     */
    #[Test]
    public function handle_skipsTrackOnInvalidArgumentAndContinues(): void
    {
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->with(
                "good_template",
                Mockery::type("array"),
                Mockery::type("array"),
            )
            ->andReturn([
                "target_directory" => "/out/good",
                "extracted_fields" => [],
                "html_elements" => [],
            ]);

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->with(
                "bad_template",
                Mockery::type("array"),
                Mockery::type("array"),
            )
            ->andThrow(new InvalidArgumentException("Template not found"));

        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->with(
                "another_template",
                Mockery::type("array"),
                Mockery::type("array"),
            )
            ->andReturn([
                "target_directory" => "/out/another",
                "extracted_fields" => [],
                "html_elements" => [],
            ]);

        $this->artisan("pnc:agent:data:update", [
            "data" => json_encode([
                "domain" => "test.com",
                "title" => "Test",
                "contentTracks" => [
                    "good_track" => "good_template",
                    "bad_track" => "bad_template",
                    "another_good" => "another_template",
                ],
            ]),
        ])
            ->expectsOutputToContain("✓ Updated: /out/good")
            ->expectsOutputToContain("Skipped [bad_track]: Template not found")
            ->expectsOutputToContain("✓ Updated: /out/another")
            ->expectsOutputToContain("Batch update pipeline completed in")
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Hard failure: generic Exception
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the transformer throws a generic Exception, the command should
     * print an error and immediately return FAILURE (no further tracks processed).
     */
    #[Test]
    public function handle_failsOnGenericException(): void
    {
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->with("template1", Mockery::type("array"), Mockery::type("array"))
            ->andThrow(new \RuntimeException("DB connection lost"));

        $this->transformer
            ->shouldNotReceive("transform")
            ->with("template2", Mockery::type("array"), Mockery::type("array"));

        $this->artisan("pnc:agent:data:update", [
            "data" => json_encode([
                "domain" => "test.com",
                "title" => "Test",
                "contentTracks" => [
                    "track1" => "template1",
                    "track2" => "template2",
                ],
            ]),
        ])
            ->expectsOutputToContain("Fatal [track1]: DB connection lost")
            ->assertFailed();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Output formatting
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The command should print the domain and title from the payload
     * before processing tracks.
     */
    #[Test]
    public function handle_printsDomainAndTitle(): void
    {
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andReturn([
                "target_directory" => "/out",
                "extracted_fields" => [],
                "html_elements" => [],
            ]);

        $this->artisan("pnc:agent:data:update", [
            "data" => json_encode([
                "domain" => "example.com",
                "title" => "My Article",
                "contentTracks" => ["track" => "tpl"],
            ]),
        ])
            ->expectsOutputToContain("Domain: example.com")
            ->expectsOutputToContain("Title:  My Article")
            ->assertSuccessful();
    }

    /**
     * The command should print separator lines (80 equals signs).
     */
    #[Test]
    public function handle_printsSeparatorLines(): void
    {
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andReturn([
                "target_directory" => "/out",
                "extracted_fields" => [],
                "html_elements" => [],
            ]);

        $this->artisan("pnc:agent:data:update", [
            "data" => json_encode([
                "domain" => "x.com",
                "title" => "T",
                "contentTracks" => ["track" => "tpl"],
            ]),
        ])
            ->expectsOutputToContain(str_repeat("=", 80))
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Logging
    // ─────────────────────────────────────────────────────────────────────

    /**
     * On successful completion, the command should log to the "daily"
     * channel with a completion message.
     */
    #[Test]
    public function handle_logsCompletionToDailyChannel(): void
    {
        $this->transformer
            ->shouldReceive("transform")
            ->once()
            ->andReturn([
                "target_directory" => "/out",
                "extracted_fields" => [],
                "html_elements" => [],
            ]);

        Log::shouldReceive("channel")
            ->once()
            ->with("daily")
            ->andReturn(
                Mockery::mock()
                    ->shouldReceive("info")
                    ->once()
                    ->with(
                        Mockery::on(
                            fn($msg) => str_contains(
                                $msg,
                                "BATCH UPDATE COMPLETED",
                            ),
                        ),
                    )
                    ->mock(),
            );

        $this->artisan("pnc:agent:data:update", [
            "data" => json_encode([
                "domain" => "x.com",
                "title" => "T",
                "contentTracks" => ["track" => "tpl"],
            ]),
        ])->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Edge case: empty contentTracks
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When contentTracks is an empty array, the command should skip
     * the loop entirely and still return SUCCESS.
     */
    #[Test]
    public function handle_succeedsWithNoTracks(): void
    {
        $this->transformer->shouldNotReceive("transform");

        $this->artisan("pnc:agent:data:update", [
            "data" => json_encode([
                "domain" => "x.com",
                "title" => "T",
                "contentTracks" => [],
            ]),
        ])
            ->expectsOutputToContain("Batch update pipeline completed in")
            ->assertSuccessful();
    }
}
