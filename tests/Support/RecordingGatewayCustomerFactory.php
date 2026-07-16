<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\Payments\Contracts\CreatesGatewayCustomer;
use App\Models\Organization;

/**
 * A gateway-customer factory that mints a UNIQUE id per call and counts how many times it
 * was asked to create one. Uniqueness means a reused id can only be explained by the
 * resolver having stored it (a deterministic id would reuse regardless), and the call
 * count proves the customer was created exactly once across many intents for one org.
 */
class RecordingGatewayCustomerFactory implements CreatesGatewayCustomer
{
    public int $calls = 0;

    public function create(Organization $organization, string $gateway): string
    {
        $this->calls++;

        return sprintf('cus_test_%s_%d', $organization->id, $this->calls);
    }
}
