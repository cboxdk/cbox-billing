<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A stored idempotency record: the first outcome of a mutating management request keyed by
 * its `Idempotency-Key`, scoped to the caller. A retry with the same key replays
 * {@see response_status}/{@see response_body}; a retry with the same key but a different
 * payload ({@see request_hash} mismatch) is a conflict.
 *
 * @property int $id
 * @property string $idempotency_key
 * @property string $scope
 * @property string $method
 * @property string $path
 * @property string $request_hash
 * @property int|null $response_status
 * @property string|null $response_body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class IdempotencyKey extends Model
{
    protected $fillable = [
        'idempotency_key', 'scope', 'method', 'path',
        'request_hash', 'response_status', 'response_body',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
        ];
    }

    /** Whether the first attempt has completed and its response is stored for replay. */
    public function isComplete(): bool
    {
        return $this->response_status !== null;
    }
}
