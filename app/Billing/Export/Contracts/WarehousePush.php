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
 *
 * SSRF-GUARD REQUIREMENT (the reason this stays a contract, not an inline call): a live push
 * dials an operator-configured warehouse endpoint, so ANY implementation of {@see push()} MUST
 * resolve the target host and refuse a request to a private, loopback, link-local, or
 * cloud-metadata address before opening the connection — a sink's `external_base`/endpoint is
 * operator input and must never be used to reach an internal service. The shipped
 * {@see NullWarehousePush} performs no network I/O and so is trivially safe; a real driver binds
 * behind this seam precisely so that guard has a single, typed place to live.
 */
interface WarehousePush
{
    /**
     * Attempt to load `$partition` into the warehouse over a live connection. Returns true when
     * a real push was performed, false when no direct-push driver is configured (the default),
     * in which case the staged files + manifest remain the delivery.
     *
     * Implementations MUST apply the SSRF guard described on this interface before any outbound
     * request — the resolved endpoint host is untrusted operator input.
     */
    public function push(SinkConfig $sink, WrittenPartition $partition): bool;
}
