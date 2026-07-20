<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Mode\BillingContext;
use Cbox\Billing\Payment\Dunning\Contracts\DunningStateStore;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningState;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Durable backing for the engine's dunning-state store — how many reminders have gone out
 * for an account and when the last one did. An account with no row reads as a fresh slate.
 * Persisting this (vs the engine's in-memory default) makes the notice cadence and the
 * minimum-notice-before-suspension gate carry correctly across scheduled runs.
 */
readonly class DatabaseDunningStateStore implements DunningStateStore
{
    private const TABLE = 'dunning_states';

    public function __construct(
        private ConnectionInterface $db,
        private BillingContext $context,
    ) {}

    public function load(string $account): DunningState
    {
        $row = $this->db->table(self::TABLE)
            ->where('account', $account)
            ->where('environment', $this->context->environmentKey())
            ->first();

        if ($row === null) {
            return DunningState::fresh();
        }

        $noticesSent = is_numeric($row->notices_sent) ? (int) $row->notices_sent : 0;
        $lastNoticeAt = is_string($row->last_notice_at)
            ? Carbon::parse($row->last_notice_at)->toDateTimeImmutable()
            : null;

        return new DunningState($noticesSent, $lastNoticeAt);
    }

    public function save(string $account, DunningState $state): void
    {
        // The plane is part of the dunning key: the SAME org id dunned in a sandbox has an
        // independent notice cadence from production, so a sandbox run never advances (or
        // suppresses) production suspension. `environment` is the key; `livemode` is its mirror.
        $this->db->table(self::TABLE)->updateOrInsert(
            ['account' => $account, 'environment' => $this->context->environmentKey()],
            [
                'livemode' => $this->context->livemode(),
                'notices_sent' => $state->noticesSent,
                'last_notice_at' => $state->lastNoticeAt?->format('Y-m-d H:i:s'),
                'updated_at' => $this->db->raw('CURRENT_TIMESTAMP'),
            ],
        );
    }
}
