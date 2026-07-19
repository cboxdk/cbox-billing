<?php

declare(strict_types=1);

namespace App\Billing\Licensing;

use App\Billing\Licensing\Contracts\LicenseRevocationRegistry;
use Cbox\Billing\Licensing\RevocationPublisher;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read model for the Licenses console area. It assembles the issued-license list with a
 * derived {@see status()} (active / expiring / expired / revoked) from the durable store
 * and the revocation registry, and exposes the distribution panel's air-gapped artifacts:
 * the issuer public key and the current signed revocation list.
 *
 * The signed revocation list needs the private key, which may be absent (the app runs fine
 * without it). So the {@see RevocationPublisher} is resolved LAZILY — only inside
 * {@see settings()} and only when a signing key is configured — rather than injected, so
 * the list and count screens still render on a key-less install.
 */
readonly class LicenseReport
{
    /** A license within this many days of expiry reads as "expiring". */
    private const int EXPIRING_WINDOW_DAYS = 30;

    public function __construct(
        private DatabaseIssuedLicenseStore $store,
        private LicenseRevocationRegistry $revocations,
        private Container $container,
        private Config $config,
    ) {}

    /**
     * Every issued license, newest first, shaped for the list screen.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $now = Carbon::now();

        return array_map(fn (IssuedLicense $license): array => [
            'id' => $license->id,
            'customer_id' => $license->customerId,
            'deployment_id' => $license->deploymentId,
            'plan' => $license->plan,
            'entitlements' => $license->entitlements,
            'limits' => $license->limits->toArray(),
            'licensed_domain' => $license->licensedDomain,
            'issued_at' => Carbon::parse($license->issuedAt->format(DATE_ATOM)),
            'expires_at' => Carbon::parse($license->expiresAt->format(DATE_ATOM)),
            'status' => $this->status($license, $now),
        ], $this->store->all());
    }

    /**
     * The paginated, optionally searched issued-license list. Search matches the customer id,
     * deployment id, or plan.
     *
     * @return LengthAwarePaginatorContract<int, array<string, mixed>>
     */
    public function paginate(?string $search = null, int $perPage = 20): LengthAwarePaginatorContract
    {
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = new Collection($this->list());

        $search = $search !== null ? trim($search) : null;

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(static function (array $row) use ($needle): bool {
                foreach (['customer_id', 'deployment_id', 'plan'] as $field) {
                    $value = $row[$field] ?? '';
                    if (is_string($value) && str_contains(mb_strtolower($value), $needle)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()],
        );
    }

    /**
     * The full detail of one license for its per-license page: its decoded contents + derived
     * status, its customer, the revocation record (reason + when) if any, and the deployment's
     * issue/renew/revoke history (a renewal is a fresh id under the same deployment).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $license = $this->store->find($id);

        if ($license === null) {
            return null;
        }

        $now = Carbon::now();
        $status = $this->status($license, $now);
        $revocation = $this->revocationRecord($id);
        $customer = $this->container->make('db')->table('organizations')->where('id', $license->customerId)->value('name');

        return [
            'id' => $license->id,
            'customer_id' => $license->customerId,
            'customer_name' => is_string($customer) ? $customer : null,
            'deployment_id' => $license->deploymentId,
            'plan' => $license->plan,
            'entitlements' => $license->entitlements,
            'limits' => $license->limits->toArray(),
            'licensed_domain' => $license->licensedDomain,
            'issued_at' => Carbon::parse($license->issuedAt->format(DATE_ATOM)),
            'not_before' => Carbon::parse($license->notBefore->format(DATE_ATOM)),
            'expires_at' => Carbon::parse($license->expiresAt->format(DATE_ATOM)),
            'key' => $license->key,
            'status' => $status,
            'revoked' => $revocation !== null,
            'revoked_at' => $revocation['revoked_at'] ?? null,
            'revoked_reason' => $revocation['reason'] ?? null,
            'history' => $this->history($license->deploymentId, $id, $revocation),
        ];
    }

    /**
     * The issue/renew/revoke timeline for a deployment: each issued_licenses row under the
     * same deployment is an issue (the first) or a renewal (a later one), newest first, with
     * the revocation appended when present.
     *
     * @param  array{revoked_at: string, reason: ?string}|null  $revocation
     * @return list<array{event: string, at: ?string, detail: string, current: bool}>
     */
    private function history(string $deploymentId, string $currentId, ?array $revocation): array
    {
        $rows = $this->container->make('db')->table('issued_licenses')
            ->where('deployment_id', $deploymentId)
            ->orderBy('created_at')
            ->orderBy('issued_at')
            ->get();

        $events = [];
        $first = true;

        foreach ($rows as $row) {
            $rowId = is_scalar($row->id) ? (string) $row->id : '';
            $events[] = [
                'event' => $first ? 'issued' : 'renewed',
                'at' => $this->stamp($row->created_at ?? $row->issued_at),
                'detail' => sprintf('License %s · expires %s', $rowId, $this->stamp($row->expires_at) ?? '—'),
                'current' => $rowId === $currentId,
            ];
            $first = false;
        }

        if ($revocation !== null) {
            $events[] = [
                'event' => 'revoked',
                'at' => $revocation['revoked_at'],
                'detail' => $revocation['reason'] ?? 'No reason recorded.',
                'current' => false,
            ];
        }

        return array_reverse($events);
    }

    /**
     * The revocation row (reason + when) for a license id, or null when it is not revoked.
     *
     * @return array{revoked_at: string, reason: ?string}|null
     */
    private function revocationRecord(string $id): ?array
    {
        $row = $this->container->make('db')->table('license_revocations')->where('license_id', $id)->first();

        if ($row === null) {
            return null;
        }

        return [
            'revoked_at' => $this->stamp($row->revoked_at) ?? '—',
            'reason' => is_string($row->reason) && $row->reason !== '' ? $row->reason : null,
        ];
    }

    private function stamp(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value)->format('Y-m-d H:i') : null;
    }

    /**
     * Standing counts for the nav + header, keyed by the same status vocabulary.
     *
     * @return array{all: int, active: int, expiring: int, expired: int, revoked: int}
     */
    public function counts(): array
    {
        $counts = ['all' => 0, 'active' => 0, 'expiring' => 0, 'expired' => 0, 'revoked' => 0];

        foreach ($this->list() as $row) {
            $counts['all']++;
            $status = $row['status'];
            if (is_string($status) && array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    /**
     * The air-gapped distribution artifacts: the public key operators bundle in the
     * verifier deployment, and the current signed revocation list (empty until a signing
     * key is configured).
     *
     * @return array{public_key: ?string, public_key_configured: bool, signing_key_configured: bool, revocation_list: string, revoked_count: int}
     */
    public function settings(): array
    {
        $publicKey = $this->config->get('billing.licensing.public_key');
        $signingKey = $this->config->get('billing.licensing.signing_key');
        $signingConfigured = is_string($signingKey) && $signingKey !== '';

        return [
            'public_key' => is_string($publicKey) && $publicKey !== '' ? $publicKey : null,
            'public_key_configured' => is_string($publicKey) && $publicKey !== '',
            'signing_key_configured' => $signingConfigured,
            'revocation_list' => $signingConfigured ? $this->signedRevocationList() : '',
            'revoked_count' => count($this->revocations->revokedIds()),
        ];
    }

    /** Cut the current signed revocation list, resolving the key-holding publisher lazily. */
    private function signedRevocationList(): string
    {
        return $this->container->make(RevocationPublisher::class)
            ->currentList(Carbon::now()->toDateTimeImmutable());
    }

    /** active / expiring / expired / revoked, deny-by-default toward the worst standing. */
    private function status(IssuedLicense $license, Carbon $now): string
    {
        if ($this->revocations->isRevoked($license->id)) {
            return 'revoked';
        }

        $expiresAt = Carbon::parse($license->expiresAt->format(DATE_ATOM));

        if ($expiresAt->isBefore($now)) {
            return 'expired';
        }

        if ($expiresAt->isBefore($now->copy()->addDays(self::EXPIRING_WINDOW_DAYS))) {
            return 'expiring';
        }

        return 'active';
    }
}
