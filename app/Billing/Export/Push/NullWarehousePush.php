<?php

declare(strict_types=1);

namespace App\Billing\Export\Push;

use App\Billing\Export\Contracts\WarehousePush;
use App\Billing\Export\Sinks\ObjectStoreSink;
use App\Billing\Export\ValueObjects\WrittenPartition;
use App\Models\WarehouseSink as SinkConfig;

/**
 * The honest default for the direct-API push seam: it performs NO live push. The real delivery
 * is the staged file + load manifest the {@see ObjectStoreSink} already
 * wrote; this default deliberately does not fabricate a warehouse client or fake any auth. It
 * returns false so callers know the load side is the operator's (or a scheduled loader's)
 * responsibility. A deployment that wants live push binds its own {@see WarehousePush}.
 */
class NullWarehousePush implements WarehousePush
{
    public function push(SinkConfig $sink, WrittenPartition $partition): bool
    {
        return false;
    }
}
