<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Export\Contracts\WarehousePush;
use App\Billing\Export\Contracts\WarehouseSink;
use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\Datasets\CouponRedemptionsDataset;
use App\Billing\Export\Datasets\CouponsDataset;
use App\Billing\Export\Datasets\CreditNotesDataset;
use App\Billing\Export\Datasets\CustomersDataset;
use App\Billing\Export\Datasets\DunningDataset;
use App\Billing\Export\Datasets\InvoiceLinesDataset;
use App\Billing\Export\Datasets\InvoicesDataset;
use App\Billing\Export\Datasets\LicensesDataset;
use App\Billing\Export\Datasets\MrrMovementsDataset;
use App\Billing\Export\Datasets\PaymentsDataset;
use App\Billing\Export\Datasets\RevenueSnapshotDataset;
use App\Billing\Export\Datasets\SeatAssignmentsDataset;
use App\Billing\Export\Datasets\SubscriptionsDataset;
use App\Billing\Export\Datasets\UsageEventsDataset;
use App\Billing\Export\Manifests\BigQueryManifest;
use App\Billing\Export\Manifests\ManifestRegistry;
use App\Billing\Export\Manifests\RedshiftManifest;
use App\Billing\Export\Manifests\SnowflakeManifest;
use App\Billing\Export\Push\NullWarehousePush;
use App\Billing\Export\Sinks\ObjectStoreSink;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the data-export / warehouse-sink module: the dataset registry (the ordered set of every
 * exportable dataset), the per-warehouse manifest generators, the real object-store sink, and
 * the honest no-op direct-push default. Everything is contract-bound so a host or plugin can
 * add a dataset, swap the sink, or wire a live push without editing calling code.
 */
class ExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatasetRegistry::class, static fn (Container $app): DatasetRegistry => new DatasetRegistry([
            $app->make(InvoicesDataset::class),
            $app->make(InvoiceLinesDataset::class),
            $app->make(SubscriptionsDataset::class),
            $app->make(CustomersDataset::class),
            $app->make(MrrMovementsDataset::class),
            $app->make(RevenueSnapshotDataset::class),
            $app->make(CreditNotesDataset::class),
            $app->make(PaymentsDataset::class),
            $app->make(CouponsDataset::class),
            $app->make(CouponRedemptionsDataset::class),
            $app->make(DunningDataset::class),
            $app->make(SeatAssignmentsDataset::class),
            $app->make(LicensesDataset::class),
            $app->make(UsageEventsDataset::class),
        ]));

        $this->app->singleton(ManifestRegistry::class, static fn (Container $app): ManifestRegistry => new ManifestRegistry([
            $app->make(SnowflakeManifest::class),
            $app->make(BigQueryManifest::class),
            $app->make(RedshiftManifest::class),
        ]));

        $this->app->bind(WarehouseSink::class, ObjectStoreSink::class);
        $this->app->bind(WarehousePush::class, NullWarehousePush::class);
    }
}
