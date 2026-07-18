<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A row in the local ACCESS MIRROR — one (organization, subject, role) grant, kept fresh
 * out-of-band by the Cbox ID provisioning webhooks. It answers "which Cbox ID subject may
 * act on which billing org, holding which role" without a token round-trip. Cbox ID
 * remains the authority for identity and assignment; this is a derived read model.
 *
 * A bare membership (no role yet) is stored with an empty-string `role`. `environment_key`
 * records the plane the grant belongs to when the event carries it (null on single-plane
 * deployments).
 *
 * @property int $id
 * @property string $organization_id
 * @property string $subject
 * @property string $role
 * @property string|null $environment_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CboxIdAccessGrant extends Model
{
    /** A bare membership grant with no assigned role. */
    public const NO_ROLE = '';

    protected $fillable = [
        'organization_id', 'subject', 'role', 'environment_key',
    ];
}
