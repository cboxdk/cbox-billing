<?php

declare(strict_types=1);

namespace App\Billing\Licensing;

use App\Billing\Mode\BillingContext;
use Cbox\Billing\Licensing\Contracts\IssuedLicenseStore;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;
use Cbox\License\ValueObjects\LicenseLimits;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use RuntimeException;
use stdClass;

/**
 * Connection-backed {@see IssuedLicenseStore} — the durable replacement for the engine's
 * in-memory default. It maps a row of {@see IssuedLicense}
 * both ways: the signed `key` artifact plus the decoded copy of its contents, so the
 * issuer console can list, renew and revoke without re-parsing the JWT.
 *
 * `save()` upserts on the license id primary key. A deployment holds at most one live
 * license, so {@see forDeployment()} returns the most recently created row for the
 * deployment — a renewal (fresh id, extended window, same deployment) supersedes the
 * prior license there while both stay findable by their own id.
 */
readonly class DatabaseIssuedLicenseStore implements IssuedLicenseStore
{
    public function __construct(
        private ConnectionInterface $db,
        private BillingContext $context,
    ) {}

    public function save(IssuedLicense $license): void
    {
        $this->table()->updateOrInsert(
            ['id' => $license->id],
            [
                'customer_id' => $license->customerId,
                'deployment_id' => $license->deploymentId,
                'plan' => $license->plan,
                'entitlements' => $this->encode($license->entitlements),
                'limits' => $this->encode($license->limits->toArray()),
                'licensed_domain' => $license->licensedDomain,
                'issued_at' => $license->issuedAt,
                'not_before' => $license->notBefore,
                'expires_at' => $license->expiresAt,
                'key' => $license->key,
                'environment' => $this->context->environmentKey(),
                'livemode' => $this->context->livemode(),
                'created_at' => Carbon::now(),
            ],
        );
    }

    public function find(string $id): ?IssuedLicense
    {
        $row = $this->scoped()->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * @return list<IssuedLicense>
     */
    public function forCustomer(string $customerId): array
    {
        return array_values($this->scoped()
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (stdClass $row): IssuedLicense => $this->hydrate($row))
            ->all());
    }

    public function forDeployment(string $deploymentId): ?IssuedLicense
    {
        // A deployment's current license is the one valid longest: a renewal reissues
        // under a fresh id with an extended `expires_at`, so it wins over the license it
        // superseded. `issued_at` / `created_at` break the (unlikely) tie deterministically.
        $row = $this->scoped()
            ->where('deployment_id', $deploymentId)
            ->orderByDesc('expires_at')
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * Every issued license, newest first — for the console list (a read the engine
     * contract does not need but the issuer screen does).
     *
     * @return list<IssuedLicense>
     */
    public function all(): array
    {
        return array_values($this->scoped()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (stdClass $row): IssuedLicense => $this->hydrate($row))
            ->all());
    }

    /**
     * The issued-license list paginated AT THE DATABASE (PERF-4), newest first, optionally
     * searched on customer id / deployment id / plan. Only the visible page is hydrated, so a
     * large issued set never loads (and JWT-decodes) every row to render one page.
     *
     * @return LengthAwarePaginator<int, IssuedLicense>
     */
    public function paginate(int $perPage, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->scoped()->orderByDesc('created_at');

        $search = $search !== null ? trim($search) : null;

        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $query->where(static function (Builder $q) use ($like): void {
                $q->where('customer_id', 'like', $like)
                    ->orWhere('deployment_id', 'like', $like)
                    ->orWhere('plan', 'like', $like);
            });
        }

        return $query->paginate($perPage)
            ->through(fn (mixed $row): IssuedLicense => $this->hydrate(
                $row instanceof stdClass ? $row : throw new RuntimeException('Issued-license query returned an unexpected row.'),
            ));
    }

    private function hydrate(stdClass $row): IssuedLicense
    {
        return new IssuedLicense(
            id: $this->str($row->id),
            key: $this->str($row->key),
            customerId: $this->str($row->customer_id),
            deploymentId: $this->str($row->deployment_id),
            plan: $this->str($row->plan),
            entitlements: $this->stringList($row->entitlements),
            limits: LicenseLimits::fromClaim($this->decodeArray($row->limits)),
            issuedAt: $this->toImmutable($row->issued_at),
            notBefore: $this->toImmutable($row->not_before),
            expiresAt: $this->toImmutable($row->expires_at),
            licensedDomain: $row->licensed_domain !== null ? $this->str($row->licensed_domain) : null,
        );
    }

    /** Coerce a mixed column value to a string. */
    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $json): array
    {
        $decoded = $this->decodeArray($json);
        $list = [];

        foreach ($decoded as $item) {
            if (is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeArray(mixed $json): array
    {
        if (! is_string($json)) {
            return [];
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function toImmutable(mixed $value): DateTimeImmutable
    {
        return Carbon::parse($this->str($value))->toDateTimeImmutable();
    }

    private function table(): Builder
    {
        return $this->db->table('issued_licenses');
    }

    /** The issued-license table constrained to the current plane (sandbox isolation for reads). */
    private function scoped(): Builder
    {
        return $this->table()->where('environment', $this->context->environmentKey());
    }
}
