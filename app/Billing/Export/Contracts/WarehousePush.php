<?php

declare(strict_types=1);

namespace App\Billing\Export\Contracts;

use App\Billing\Export\Push\NullWarehousePush;
use App\Billing\Export\ValueObjects\WrittenPartition;
use App\Models\WarehouseSink as SinkConfig;

/**
 * The OPTIONAL direct-API push seam, kept honest. The real, always-available delivery path is
 * the staged-file + load-manifest flow ({@see WarehouseSink} → {@see LoadManifestGenerator});
 * this contract is the extension point a deployment wires when it wants the app to ALSO push
 * the staged partition into the warehouse over a live API (a JDBC/HTTP loader, a Snowpipe
 * REST call, a `bq` invocation) instead of leaving the load to an operator.
 *
 * The shipped default is {@see NullWarehousePush} — a no-op that
 * records the staged location and does NOT fabricate a warehouse client or fake any auth. A
 * deployment binds its own implementation to turn live push on; the boundary is documented in
 * docs/data-export/warehouse-sinks.
 */
interface WarehousePush
{
    /**
     * Attempt to load `$partition` into the warehouse over a live connection. Returns true when
     * a real push was performed, false when no direct-push driver is configured (the default),
     * in which case the staged files + manifest remain the delivery.
     */
    public function push(SinkConfig $sink, WrittenPartition $partition): bool;
}
