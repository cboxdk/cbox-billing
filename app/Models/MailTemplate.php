<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Notifications\MailEventType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator-authored override of a transactional-email template for a single
 * (event_type, locale, seller_entity_id) key. Absent a row, the shipped default in code
 * renders; a row here supersedes it. The body is authored in the restricted, sandboxed
 * mustache syntax — never Blade/PHP — so persisting one can never introduce code execution.
 *
 * @property int $id
 * @property string $event_type
 * @property string $locale
 * @property string|null $seller_entity_id
 * @property string $subject
 * @property string $body
 */
class MailTemplate extends Model
{
    protected $fillable = ['event_type', 'locale', 'seller_entity_id', 'subject', 'body'];

    /** @return BelongsTo<SellerEntity, $this> */
    public function sellerEntity(): BelongsTo
    {
        return $this->belongsTo(SellerEntity::class);
    }

    /** The event type this template renders, or null if it references a retired/unknown key. */
    public function eventType(): ?MailEventType
    {
        return MailEventType::tryFrom($this->event_type);
    }

    /** @return Attribute<string, string> */
    protected function locale(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => strtolower($value),
        );
    }
}
