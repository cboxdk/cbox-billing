<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\Support;

use App\Billing\Environments\Promotion\Descriptors\ObjectDescriptor;
use Illuminate\Database\Eloquent\Model;

/**
 * A selected source object bound to its type descriptor and its (already-computed) stable natural
 * key — the unit the promotion engine iterates when diffing and applying. Internal to the engine.
 */
readonly class ResolvedObject
{
    public function __construct(
        public ObjectDescriptor $descriptor,
        public Model $row,
        public string $naturalKey,
    ) {}
}
