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
 * Unit tests for PncTemplatesServiceProvider.
 *
 * Verifies:
 *  - register() binds the interface to the concrete service.
 *  - The binding is non-singleton (each resolution creates a new instance).
 *  - boot() does not throw (config merge is optional/safe).
 */
final class PncTemplatesServiceProviderTest extends TestCase
{
    /**
     * Ensure the service provider is registered for this test.
     */
    protected function getPackageProviders($app): array
    {
        return [PncTemplatesServiceProvider::class];
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
     * boot() completes without error regardless of whether
     * config/templates.php exists.
     */
    #[Test]
    public function boot_completes_without_error(): void
    {
        $provider = new PncTemplatesServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertTrue(true); // No exception = pass
    }

    /**
     * After boot(), the "templates" config key exists if the
     * config file is present, or is absent if it is not.
     *
     * This is a soft check — it documents the conditional behaviour.
     */
    #[Test]
    public function config_key_exists_only_if_file_present(): void
    {
        $configFile = app_path("PncTemplates/config/templates.php");

        $provider = new PncTemplatesServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        if (file_exists($configFile)) {
            $this->assertNotNull(config("templates"));
        } else {
            $this->assertNull(config("templates"));
        }
    }
}
