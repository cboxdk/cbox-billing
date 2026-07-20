<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One gateway's credentials for one billing {@see Environment} (plane). The secret / publishable
 * / webhook-signing secret are stored ENCRYPTED at rest (the app key is the only thing that
 * decrypts them); the plaintext never lands in the database or a log.
 *
 * This model deliberately does NOT compose {@see BelongsToEnvironment}:
 * gateway resolution has to read the row for a SPECIFIC plane (often before the ambient context
 * is set, and cross-plane during a teardown), so the plane is always an explicit
 * `where('environment', …)` filter rather than the ambient global scope. It is CONFIG, not tenant
 * data — a sandbox reset keeps it, an environment destroy deletes it.
 *
 * @property int $id
 * @property string $environment
 * @property string $gateway
 * @property string $secret
 * @property string|null $publishable
 * @property string|null $webhook_secret
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EnvironmentGateway extends Model
{
    protected $fillable = [
        'environment', 'gateway', 'secret', 'publishable', 'webhook_secret', 'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'publishable' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'active' => 'boolean',
        ];
    }
}
