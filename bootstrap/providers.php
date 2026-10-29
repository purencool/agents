<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\PncAgentics\PncAgenticsServiceProvider::class,
    App\PncServices\PncServicesServiceProvider::class,
    App\PncTemplates\PncTemplatesServiceProvider::class,
];
