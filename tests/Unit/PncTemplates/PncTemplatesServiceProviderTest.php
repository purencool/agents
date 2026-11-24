<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncTemplates;

use App\PncTemplates\Contracts\TemplateTransformerInterface;
use App\PncTemplates\PncTemplatesServiceProvider;
use App\PncTemplates\Services\TemplateTransformerService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see PncTemplatesServiceProvider}.
 *
 * Verifies:
 *  - register() binds TemplateTransformerInterface to TemplateTransformerService.
 *  - The binding is non-singleton (each resolution creates a new instance).
 *  - boot() completes without error (currently a no-op).
 *
 * @covers \App\PncTemplates\PncTemplatesServiceProvider
 */
final class PncTemplatesServiceProviderTest extends TestCase
{
    /**
     * Register the service provider for this test.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            PncTemplatesServiceProvider::class,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // register()
    // ─────────────────────────────────────────────────────────────

    /**
     * The interface is bound to TemplateTransformerService in the container.
     */
    #[Test]
    public function interface_is_bound_to_concrete_service(): void
    {
        $this->assertArrayHasKey(
            TemplateTransformerInterface::class,
            $this->app->getBindings(),
        );
    }

    /**
     * Resolving the interface from the container returns a
     * TemplateTransformerService instance.
     */
    #[Test]
    public function resolving_interface_returns_service_instance(): void
    {
        $instance = $this->app->make(TemplateTransformerInterface::class);

        $this->assertInstanceOf(TemplateTransformerService::class, $instance);
    }

    /**
     * The binding is non-singleton: each make() call returns a
     * distinct instance.
     */
    #[Test]
    public function binding_is_not_singleton(): void
    {
        $a = $this->app->make(TemplateTransformerInterface::class);
        $b = $this->app->make(TemplateTransformerInterface::class);

        $this->assertNotSame($a, $b);
    }

    // ─────────────────────────────────────────────────────────────
    // boot()
    // ─────────────────────────────────────────────────────────────

    /**
     * boot() completes without error.
     *
     * The provider was already booted by the test framework via
     * getPackageProviders(), so we simply verify the app is functional
     * after the boot cycle.
     */
    #[Test]
    public function boot_completes_without_error(): void
    {
        // The provider was registered and booted by the framework.
        // If boot() threw, the test would have failed during setup.
        $this->assertTrue(true);
    }
}
