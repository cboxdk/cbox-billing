<?php

use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\CboxIdWebhookServiceProvider;
use App\Providers\ConsoleServiceProvider;
use App\Providers\LicensingServiceProvider;

return [
    AppServiceProvider::class,
    ConsoleServiceProvider::class,
    BillingServiceProvider::class,
    LicensingServiceProvider::class,
    CboxIdWebhookServiceProvider::class,
];
