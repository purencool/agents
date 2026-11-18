<?php

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Services;

use App\PncAgentics\Contracts\McpToolServiceInterface;
use App\PncAgentics\Services\McpToolService;
use App\PncAgentics\Services\McpToolServiceProvider;
use App\PncAgentics\Tools\McpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see McpToolServiceProvider}.
 *
 * @covers \App\PncAgentics\Services\McpToolServiceProvider
 */
class McpToolServiceProviderTest extends TestCase
{
    /**
     * The service provider should register a binding that resolves
     * McpToolServiceInterface to McpToolService.
     */
    #[Test]
    public function register_bindsInterfaceToImplementation(): void
    {
        $provider = new McpToolServiceProvider($this->app);
        $provider->register();

        $this->assertTrue(
            $this->app->bound(McpToolServiceInterface::class),
            "McpToolServiceInterface should be bound in the container.",
        );
    }

    /**
     * Resolving the interface from the container should return an
     * instance of McpToolService.
     */
    #[Test]
    public function resolve_returnsMcpToolServiceInstance(): void
    {
        $provider = new McpToolServiceProvider($this->app);
        $provider->register();

        // Bind the McpClient dependency so resolution doesn't fail.
        $this->app->bind(
            McpClient::class,
            fn() => $this->createMock(McpClient::class),
        );

        $resolved = $this->app->make(McpToolServiceInterface::class);

        $this->assertInstanceOf(McpToolService::class, $resolved);
    }

    /**
     * The binding is transient (non-shared), so each resolution should
     * produce a distinct instance.
     */
    #[Test]
    public function bindingIsTransient_notSingleton(): void
    {
        $provider = new McpToolServiceProvider($this->app);
        $provider->register();

        $this->app->bind(
            McpClient::class,
            fn() => $this->createMock(McpClient::class),
        );

        $first = $this->app->make(McpToolServiceInterface::class);
        $second = $this->app->make(McpToolServiceInterface::class);

        $this->assertNotSame($first, $second);
    }
}
