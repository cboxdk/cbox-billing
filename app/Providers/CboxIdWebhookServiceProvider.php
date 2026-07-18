<?php

declare(strict_types=1);

namespace App\Providers;

use App\Identity\CboxIdProvisioningSync;
use App\Identity\Contracts\SyncsIdentityProvisioning;
use Cbox\Id\Client\Facades\CboxIdWebhooks;
use Cbox\Id\Client\Webhooks\WebhookEvent;
use Illuminate\Support\ServiceProvider;

/**
 * Wires Cbox ID's provisioning webhooks to this app's out-of-band sync. The SDK verifies
 * each event's HMAC signature at the receiver and runs the registered handlers on its
 * queued job; here we subscribe the exact set of events Cbox ID emits for
 * organization/role provisioning and hand each to {@see SyncsIdentityProvisioning}, which
 * maintains the access mirror + seat counts + org standing idempotently per delivery.
 *
 * The provisioning-webhook receiver itself is mounted in routes/webhooks.php (public,
 * HMAC-verified by the SDK, deny-by-default when no `CBOX_ID_WEBHOOK_SECRET` is set).
 */
class CboxIdWebhookServiceProvider extends ServiceProvider
{
    /**
     * The Cbox ID provisioning events this app reacts to (verified against the id source).
     * A membership/role change keeps the access mirror + seats fresh; a suspend/reactivate
     * reflects the org's standing.
     *
     * @var list<string>
     */
    private const EVENTS = [
        'organization.member_added',
        'organization.member_role_changed',
        'organization.member_removed',
        'role.assigned',
        'role.revoked',
        'directory.user.provisioned',
        'organization.suspended',
        'organization.reactivated',
    ];

    public function register(): void
    {
        $this->app->singleton(SyncsIdentityProvisioning::class, CboxIdProvisioningSync::class);
    }

    public function boot(): void
    {
        foreach (self::EVENTS as $event) {
            CboxIdWebhooks::on($event, function (WebhookEvent $received): void {
                $this->app->make(SyncsIdentityProvisioning::class)->handle($received);
            });
        }
    }
}
