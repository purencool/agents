<?php

namespace App\PncServices;

use Illuminate\Support\ServiceProvider;
use App\PncServices\Contracts\EmailServiceInterface;
use App\PncServices\Contracts\AIServiceInterface;
use App\PncServices\Contracts\SMSInterface;
use App\PncServices\Contracts\RestServiceInterface;
use App\PncServices\Contracts\LoggingServiceInterface;
use App\PncServices\Logging\LoggingService;
use App\PncServices\Email\EmailService;
use App\PncServices\AI\AIService;
use App\PncServices\SMS\SMS;
use App\PncServices\REST\RestService;

class PncServicesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmailServiceInterface::class, EmailService::class);
        $this->app->bind(AIServiceInterface::class, AIService::class);
        $this->app->bind(SMSInterface::class, SMS::class);
        $this->app->bind(RestServiceInterface::class, RestService::class);
        $this->app->bind(LoggingServiceInterface::class, LoggingService::class);
    }

    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/pncservices.php', 'pncservices');
    }
}
