<?php

declare(strict_types=1);

namespace App\Billing\Catalog;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Models\Product;

/**
 * Create / edit / archive / delete a catalog {@see Product}. A product groups plans but
 * carries no money itself, so authoring it is plain metadata — the guard that matters is
 * on removal: a product that still groups plans is archived (soft-deactivated), never
 * hard-deleted, so its plans and their grandfathered subscribers are never orphaned. Only
 * a product with zero plans is ever deleted outright.
 */
readonly class ProductAuthoring
{
    /**
     * @param  array{key: string, name: string, description: ?string}  $data
     */
    public function create(array $data): Product
    {
        $this->assertKeyUnique($data['key'], null);

        return Product::query()->create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'],
        ]);
    }

    /**
     * @param  array{key: string, name: string, description: ?string}  $data
     */
    public function update(Product $product, array $data): Product
    {
        $this->assertKeyUnique($data['key'], $product->id);

        $product->update([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'],
        ]);

        return $product;
    }

    /** Soft-deactivate the product; its plans and subscribers are untouched. */
    public function archive(Product $product): void
    {
        $product->forceFill(['archived_at' => now()])->save();
    }

    /** Reinstate an archived product. */
    public function unarchive(Product $product): void
    {
        $product->forceFill(['archived_at' => null])->save();
    }

    /**
     * Hard-delete a product — refused while it still groups plans (archive those, or the
     * product, instead), so catalog history is never orphaned.
     */
    public function delete(Product $product): void
    {
        $plans = $product->plans()->count();

        if ($plans > 0) {
            throw CatalogActionDenied::productHasPlans($product->name, $plans);
        }

        $product->delete();
    }

    private function assertKeyUnique(string $key, ?int $ignoreId): void
    {
        $exists = Product::query()
            ->where('key', $key)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw CatalogActionDenied::duplicateKey($key);
        }
    }
}
