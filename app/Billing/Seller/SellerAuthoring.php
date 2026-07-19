<?php

declare(strict_types=1);

namespace App\Billing\Seller;

use App\Billing\Seller\Exceptions\SellerActionDenied;
use App\Models\Invoice;
use App\Models\SellerEntity;
use Illuminate\Support\Facades\DB;

/**
 * Create / edit / archive / delete a selling entity of record and its per-jurisdiction tax
 * registrations. The delete guard is the one that matters: a seller whose `invoice_prefix`
 * still numbers finalized invoices is archived (soft), never hard-deleted, so the legal
 * record is never orphaned; the default entity cannot be deleted until another is made
 * default. Registrations are authored here (nexus), never rate numbers — those come from the
 * cited rate-source feeds.
 */
readonly class SellerAuthoring
{
    /**
     * @param  array{id: string, legal_name: string, registration_number: string, establishment: string, currency: string, invoice_prefix: string, is_default: bool, registrations: list<array{country: string, number: string, subdivision: ?string, scheme: ?string}>}  $data
     */
    public function create(array $data): SellerEntity
    {
        if (SellerEntity::query()->whereKey($data['id'])->exists()) {
            throw SellerActionDenied::duplicateId($data['id']);
        }

        // The first entity authored becomes the default — a deployment always has one.
        $isDefault = $data['is_default'] || SellerEntity::query()->where('is_default', true)->doesntExist();

        return DB::transaction(function () use ($data, $isDefault): SellerEntity {
            if ($isDefault) {
                SellerEntity::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $seller = SellerEntity::query()->create([
                'id' => $data['id'],
                'legal_name' => $data['legal_name'],
                'registration_number' => $data['registration_number'],
                'establishment' => $data['establishment'],
                'currency' => $data['currency'],
                'invoice_prefix' => $data['invoice_prefix'],
                'is_default' => $isDefault,
            ]);

            $this->syncRegistrations($seller, $data['registrations']);

            return $seller;
        });
    }

    /**
     * @param  array{legal_name: string, registration_number: string, establishment: string, currency: string, invoice_prefix: string, is_default: bool, registrations: list<array{country: string, number: string, subdivision: ?string, scheme: ?string}>}  $data
     */
    public function update(SellerEntity $seller, array $data): SellerEntity
    {
        return DB::transaction(function () use ($seller, $data): SellerEntity {
            if ($data['is_default'] && ! $seller->is_default) {
                SellerEntity::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $seller->update([
                'legal_name' => $data['legal_name'],
                'registration_number' => $data['registration_number'],
                'establishment' => $data['establishment'],
                'currency' => $data['currency'],
                'invoice_prefix' => $data['invoice_prefix'],
                // The default flag is never cleared by an edit — a deployment always keeps one
                // default; demote by promoting another entity instead.
                'is_default' => $seller->is_default || $data['is_default'],
            ]);

            $this->syncRegistrations($seller, $data['registrations']);

            return $seller;
        });
    }

    public function archive(SellerEntity $seller): void
    {
        $seller->forceFill(['archived_at' => now()])->save();
    }

    public function unarchive(SellerEntity $seller): void
    {
        $seller->forceFill(['archived_at' => null])->save();
    }

    /** Promote a seller to the default, demoting the incumbent — a deployment always has one. */
    public function setDefault(SellerEntity $seller): void
    {
        DB::transaction(function () use ($seller): void {
            SellerEntity::query()->where('is_default', true)->update(['is_default' => false]);
            $seller->forceFill(['is_default' => true, 'archived_at' => null])->save();
        });
    }

    /**
     * Hard-delete a seller — refused while its prefix still numbers invoices (archive instead)
     * or while it is the default (promote another first), so the legal record is never orphaned.
     */
    public function delete(SellerEntity $seller): void
    {
        if ($seller->is_default) {
            throw SellerActionDenied::isDefault($seller->legal_name);
        }

        $invoices = $this->invoicesFor($seller);

        if ($invoices > 0) {
            throw SellerActionDenied::referencedByInvoices($seller->legal_name, $invoices);
        }

        $seller->delete();
    }

    /** Invoices this seller has numbered (its `invoice_prefix` is the number stem). */
    public function invoicesFor(SellerEntity $seller): int
    {
        return Invoice::query()->where('number', 'like', $seller->invoice_prefix.'%')->count();
    }

    /**
     * @param  list<array{country: string, number: string, subdivision: ?string, scheme: ?string}>  $registrations
     */
    private function syncRegistrations(SellerEntity $seller, array $registrations): void
    {
        $seller->taxRegistrations()->delete();

        foreach ($registrations as $registration) {
            $seller->taxRegistrations()->create([
                'country' => $registration['country'],
                'number' => $registration['number'],
                'subdivision' => $registration['subdivision'],
                'scheme' => $registration['scheme'],
            ]);
        }
    }
}
