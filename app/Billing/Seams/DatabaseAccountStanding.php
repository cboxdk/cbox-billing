<?php

declare(strict_types=1);

namespace App\Billing\Seams;

use App\Billing\Mode\BillingContext;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Enums\AccountStandingState;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Durable {@see AccountStanding}: one row per flagged account in `account_standings`,
 * keyed on the billing account (the `org` identifier). Replaces the engine's
 * in-memory default so a chargeback-driven standing survives a process restart.
 *
 * Deny-by-default does not apply here in reverse: an account with no row reads as
 * {@see AccountStandingState::Good} — standing is a positive record of trouble, not an
 * authorization allow-list, so an untouched account keeps normal access.
 */
readonly class DatabaseAccountStanding implements AccountStanding
{
    private const TABLE = 'account_standings';

    public function __construct(
        private ConnectionInterface $db,
        private BillingContext $context,
    ) {}

    public function standingOf(string $account): AccountStandingState
    {
        $row = $this->db->table(self::TABLE)
            ->where('account', $account)
            ->where('livemode', $this->context->livemode())
            ->first();

        if ($row instanceof stdClass && is_string($row->state)) {
            return AccountStandingState::tryFrom($row->state) ?? AccountStandingState::Good;
        }

        return AccountStandingState::Good;
    }

    public function flag(string $account, AccountStandingState $state, string $reason): void
    {
        // The plane is part of the standing key: the SAME org id carries an independent standing
        // per plane, so a test chargeback never flags the live account and vice-versa.
        $now = $this->db->raw('CURRENT_TIMESTAMP');
        $livemode = $this->context->livemode();
        $existing = $this->db->table(self::TABLE)
            ->where('account', $account)
            ->where('livemode', $livemode)
            ->exists();

        if ($existing) {
            $this->db->table(self::TABLE)
                ->where('account', $account)
                ->where('livemode', $livemode)
                ->update([
                    'state' => $state->value,
                    'reason' => $reason,
                    'updated_at' => $now,
                ]);

            return;
        }

        $this->db->table(self::TABLE)->insert([
            'account' => $account,
            'livemode' => $livemode,
            'state' => $state->value,
            'reason' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
