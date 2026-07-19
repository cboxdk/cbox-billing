<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A bearer token the enforcement API authenticates against. A token scoped to an
 * `organization_id` may act only for that org; a token with a null `organization_id`
 * is an operator token allowed to act for any org. Only the SHA-256 `hash` is stored —
 * the plaintext is returned once from {@see issue()} and never persisted.
 *
 * @property int $id
 * @property string $name
 * @property string|null $organization_id
 * @property int|null $product_id
 * @property string|null $created_by_sub
 * @property string $hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
class ApiToken extends Model
{
    protected $fillable = ['name', 'organization_id', 'product_id', 'created_by_sub', 'hash', 'last_used_at', 'revoked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** A token is live until it is revoked; a revoked token no longer authenticates. */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Soft-revoke the token — it stops authenticating immediately, its audit row survives. */
    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => Carbon::now()])->save();
        }
    }

    /**
     * Issue a fresh token, returning the one-time plaintext alongside the stored row.
     * `$createdBySub` records the Cbox ID subject of the minting operator (SEC-1 audit) —
     * null for CLI-issued tokens that carry no console session.
     *
     * @return array{token: self, plaintext: string}
     */
    public static function issue(string $name, ?string $organizationId = null, ?int $productId = null, ?string $createdBySub = null): array
    {
        $plaintext = Str::random(48);

        $token = self::query()->create([
            'name' => $name,
            'organization_id' => $organizationId,
            'product_id' => $productId,
            'created_by_sub' => $createdBySub,
            'hash' => hash('sha256', $plaintext),
        ]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
