<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\CboxIdWebhookServiceProvider;
use App\Providers\ConsoleServiceProvider;
use App\Providers\ExportServiceProvider;
use App\Providers\LicensingServiceProvider;
use App\Providers\TaxExemptionServiceProvider;
use App\Providers\TestModeServiceProvider;
use App\Providers\WebhookServiceProvider;

return [
    TestModeServiceProvider::class,
    AppServiceProvider::class,
    ConsoleServiceProvider::class,
    BillingServiceProvider::class,
    TaxExemptionServiceProvider::class,
    ExportServiceProvider::class,
    AuditServiceProvider::class,
    LicensingServiceProvider::class,
    CboxIdWebhookServiceProvider::class,
    WebhookServiceProvider::class,
];
