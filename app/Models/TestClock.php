<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\TestMode\Enums\TestChargeOutcome;
use App\Billing\TestMode\TestClockAdvancer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A test clock: a named, fast-forwardable virtual clock the sandbox binds test subscriptions
 * to. Its `now_at` is the current virtual time the bound subscriptions' billing decisions
 * read; advancing it (see {@see TestClockAdvancer}) steps that time
 * forward and runs the due billing logic exactly as it would have fired over real elapsed
 * time. `charge_outcome` fixes whether the fake gateway settles or declines the bound
 * subscriptions' charges — the deterministic switch that drives the dunning flow on demand.
 *
 * A clock is a test-only object (there is no "live clock"), so it carries no plane scope of
 * its own — it is always `livemode = false` and reached only through the operator console or a
 * test-mode API token. Its bound {@see Subscription}s, by contrast, ARE plane-scoped.
 *
 * `organization_id` optionally scopes the clock to one org: the programmatic advance asserts the
 * caller may act for it, so an org-scoped test token cannot fast-forward another org's clock. A
 * null org keeps a clock operator-only on the API (still reachable from the console).
 *
 * @property int $id
 * @property string $name
 * @property string|null $organization_id
 * @property Carbon $now_at
 * @property string $charge_outcome
 * @property bool $livemode
 * @property string|null $created_by_sub
 */
class TestClock extends Model
{
    protected $fillable = ['name', 'organization_id', 'now_at', 'charge_outcome', 'created_by_sub'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'now_at' => 'datetime',
        ];
    }

    /** The virtual current time as a {@see CarbonImmutable}. */
    public function virtualNow(): CarbonImmutable
    {
        return $this->now_at->toImmutable();
    }

    /** How the fake gateway resolves this clock's charges (settle vs decline). */
    public function chargeOutcome(): TestChargeOutcome
    {
        return TestChargeOutcome::parse($this->charge_outcome);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
