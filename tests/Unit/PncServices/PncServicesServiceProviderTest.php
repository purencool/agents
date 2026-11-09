<?php

namespace Tests\Unit\PncServices;

use App\PncServices\Contracts\AgentDataInterface;
use App\PncServices\Contracts\AIServiceInterface;
use App\PncServices\Contracts\EmailServiceInterface;
use App\PncServices\Contracts\LoggingServiceInterface;
use App\PncServices\Contracts\RestServiceInterface;
use App\PncServices\Contracts\SMSInterface;
use App\PncServices\Agent\AgentDataService;
use App\PncServices\AI\AIService;
use App\PncServices\Email\EmailService;
use App\PncServices\Logging\LoggingService;
use App\PncServices\REST\RestService;
use App\PncServices\SMS\SMS;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smoke test to check for small errors.
 */
class PncServicesServiceProviderTest extends TestCase
{
    #[Test]
    public function it_binds_all_interfaces_to_their_implementations(): void
    {
        $bindings = [
            EmailServiceInterface::class => EmailService::class,
            AIServiceInterface::class => AIService::class,
            SMSInterface::class => SMS::class,
            RestServiceInterface::class => RestService::class,
            LoggingServiceInterface::class => LoggingService::class,
            AgentDataInterface::class => AgentDataService::class,
        ];

        foreach ($bindings as $abstract => $concrete) {
            $this->assertInstanceOf($concrete, $this->app->make($abstract));
        }
    }
}
