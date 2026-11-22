<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace App\PncServices;

use Illuminate\Support\ServiceProvider;
use App\PncServices\Contracts\ContentPersistenceInterface;
use App\PncServices\Contracts\EmailServiceInterface;
use App\PncServices\Contracts\SMSInterface;
use App\PncServices\Contracts\RestServiceInterface;
use App\PncServices\Contracts\LoggingServiceInterface;
use App\PncServices\Logging\LoggingService;
use App\PncServices\Email\EmailService;
use App\PncServices\SMS\SMS;
use App\PncServices\REST\RestService;
use App\PncServices\Storage\FileContentPersistence;

class PncServicesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmailServiceInterface::class, EmailService::class);
        $this->app->bind(SMSInterface::class, SMS::class);
        $this->app->bind(RestServiceInterface::class, RestService::class);
        $this->app->bind(LoggingServiceInterface::class, LoggingService::class);

        $this->app->bind(
            ContentPersistenceInterface::class,
            FileContentPersistence::class,
        );
    }

    public function boot(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . "/../../config/pnc.php",
            "pncservices",
        );
    }
}
