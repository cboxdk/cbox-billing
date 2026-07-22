<?php

use App\Providers\ApprovalServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\CboxIdWebhookServiceProvider;
use App\Providers\ConsoleServiceProvider;
use App\Providers\CpqServiceProvider;
use App\Providers\ExportServiceProvider;
use App\Providers\ImportServiceProvider;
use App\Providers\LicensingServiceProvider;
use App\Providers\NexusServiceProvider;
use App\Providers\TaxExemptionServiceProvider;
use App\Providers\TestModeServiceProvider;
use App\Providers\WebhookServiceProvider;

return [
    TestModeServiceProvider::class,
    AppServiceProvider::class,
    ConsoleServiceProvider::class,
    BillingServiceProvider::class,
    CpqServiceProvider::class,
    TaxExemptionServiceProvider::class,
    NexusServiceProvider::class,
    ExportServiceProvider::class,
    ImportServiceProvider::class,
    AuditServiceProvider::class,
    ApprovalServiceProvider::class,
    LicensingServiceProvider::class,
    CboxIdWebhookServiceProvider::class,
    WebhookServiceProvider::class,
];
