<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Billing\Seller\SellerCatalog;
use App\Models\SellerEntity;
use Illuminate\Database\Seeder;

/**
 * Seed the `seller_entities` register from the `billing.seller` config so the console's
 * seller CRUD opens on the same identity a fresh install ships in config. Idempotent and
 * additive: it skips any entity already authored (so it never clobbers an operator edit) and
 * leaves the config as the fallback the {@see SellerCatalog} still reads
 * when the table is empty.
 */
class SellerEntitySeeder extends Seeder
{
    public function run(): void
    {
        $entities = config('billing.seller.entities');
        $default = config('billing.seller.default');

        if (! is_array($entities)) {
            return;
        }

        foreach ($entities as $id => $definition) {
            $id = (string) $id;

            if (! is_array($definition) || SellerEntity::query()->whereKey($id)->exists()) {
                continue;
            }

            $seller = SellerEntity::query()->create([
                'id' => $id,
                'legal_name' => self::str($definition, 'legal_name'),
                'registration_number' => self::str($definition, 'registration_number'),
                'establishment' => self::str($definition, 'establishment'),
                'currency' => self::str($definition, 'currency'),
                'invoice_prefix' => self::str($definition, 'invoice_prefix'),
                'is_default' => $id === $default,
            ]);

            $registrations = $definition['tax_registrations'] ?? [];

            foreach (is_array($registrations) ? $registrations : [] as $registration) {
                if (! is_array($registration)) {
                    continue;
                }

                $seller->taxRegistrations()->create([
                    'country' => self::str($registration, 'country'),
                    'number' => self::str($registration, 'number'),
                    'subdivision' => is_string($registration['subdivision'] ?? null) ? $registration['subdivision'] : null,
                    'scheme' => is_string($registration['scheme'] ?? null) ? $registration['scheme'] : null,
                ]);
            }
        }

        // A deployment always has exactly one default; if config named none, promote the first.
        if (SellerEntity::query()->where('is_default', true)->doesntExist()) {
            $first = SellerEntity::query()->orderBy('id')->first();
            $first?->forceFill(['is_default' => true])->save();
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
