<?php

declare(strict_types=1);

namespace Tests\Feature;

use Cbox\License\Capabilities;
use Cbox\License\Contracts\CapabilityGate;
use Cbox\License\DenyingCapabilityGate;
use Cbox\License\Ed25519LicenseIssuer;
use Cbox\License\LicenseCapabilityGate;
use Cbox\License\Support\Ed25519KeyPair;
use Cbox\License\ValueObjects\LicenseLimits;
use Cbox\License\ValueObjects\LicenseRequest;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The composed-deployment seam: this base app binds ONE {@see CapabilityGate}, and every
 * bundled commercial plugin reads it to decide whether a paid capability is unlocked.
 *
 * Proven against a REAL Ed25519 keypair and the REAL {@see Ed25519LicenseVerifier} the
 * provider wires — never a success-mock. With no consume-license the gate denies by default
 * (free tier); with a valid consume-license it unlocks EXACTLY that license's entitlements.
 */
class CapabilityGateBindingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_gate_denies_by_default_when_no_consume_license_is_configured(): void
    {
        config(['billing.licensing.consume_key' => null]);

        // Never resolved at boot; resolving now runs the lazy singleton closure.
        $gate = $this->app->make(CapabilityGate::class);

        $this->assertInstanceOf(DenyingCapabilityGate::class, $gate);

        // An unconfigured deployment unlocks nothing — every capability stays locked.
        foreach ([
            Capabilities::MULTI_TENANT_PLATFORM,
            Capabilities::SSO,
            Capabilities::SAML,
            Capabilities::SCIM,
            Capabilities::ANALYTICS,
            Capabilities::COMPLIANCE,
            Capabilities::SUPPORT,
            'some.app.specific.capability',
        ] as $capability) {
            $this->assertFalse($gate->allows($capability), $capability.' must stay locked by default');
        }
    }

    public function test_a_valid_consume_license_unlocks_exactly_its_entitlements(): void
    {
        $keyPair = Ed25519KeyPair::generate();
        $now = new DateTimeImmutable('2026-07-16T12:00:00Z');
        $deploymentId = 'dep_self';

        // The exact set this deployment's license grants.
        $granted = [Capabilities::SSO, Capabilities::SCIM, Capabilities::SUPPORT];

        // Mint a real, signed consume-license bound to THIS deployment id.
        $consumeKey = (new Ed25519LicenseIssuer($keyPair['privateKey']))->issue(new LicenseRequest(
            plan: 'team-onprem',
            entitlements: $granted,
            limits: new LicenseLimits(organizations: 5, seats: 50, environments: 2),
            customerId: 'org_self',
            deploymentId: $deploymentId,
            licensedDomain: null,
            issuedAt: $now,
            notBefore: $now,
            expiresAt: $now->modify('+1 year'),
        ));

        config([
            'billing.licensing.public_key' => $keyPair['publicKey'],
            'billing.licensing.consume_key' => $consumeKey,
            'billing.licensing.deployment_id' => $deploymentId,
            'billing.licensing.grace_seconds' => 14 * 86_400,
            'billing.licensing.clock_skew_seconds' => 60,
        ]);

        // Freeze the clock inside the license window so the provider verifies "valid".
        Carbon::setTestNow('2026-08-01T00:00:00Z');

        $gate = $this->app->make(CapabilityGate::class);

        $this->assertInstanceOf(LicenseCapabilityGate::class, $gate);

        // Allows EXACTLY the license's entitlements.
        foreach ($granted as $capability) {
            $this->assertTrue($gate->allows($capability), $capability.' should be unlocked by the license');
        }

        // Denies every capability the license did NOT grant.
        foreach ([
            Capabilities::MULTI_TENANT_PLATFORM,
            Capabilities::SAML,
            Capabilities::ANALYTICS,
            Capabilities::COMPLIANCE,
            Capabilities::RISK_PLUS,
            Capabilities::WHITELABEL,
            Capabilities::CONNECTORS,
            'unknown.capability',
        ] as $capability) {
            $this->assertFalse($gate->allows($capability), $capability.' must stay locked (not in the license)');
        }
    }

    public function test_a_consume_license_bound_to_another_deployment_unlocks_nothing(): void
    {
        $keyPair = Ed25519KeyPair::generate();
        $now = new DateTimeImmutable('2026-07-16T12:00:00Z');

        // License is bound to dep_other, but this deployment identifies as dep_self.
        $consumeKey = (new Ed25519LicenseIssuer($keyPair['privateKey']))->issue(new LicenseRequest(
            plan: 'team-onprem',
            entitlements: [Capabilities::SSO, Capabilities::SUPPORT],
            limits: new LicenseLimits(seats: 50),
            customerId: 'org_self',
            deploymentId: 'dep_other',
            licensedDomain: null,
            issuedAt: $now,
            notBefore: $now,
            expiresAt: $now->modify('+1 year'),
        ));

        config([
            'billing.licensing.public_key' => $keyPair['publicKey'],
            'billing.licensing.consume_key' => $consumeKey,
            'billing.licensing.deployment_id' => 'dep_self',
            'billing.licensing.grace_seconds' => 14 * 86_400,
            'billing.licensing.clock_skew_seconds' => 60,
        ]);

        Carbon::setTestNow('2026-08-01T00:00:00Z');

        $gate = $this->app->make(CapabilityGate::class);

        // Deployment-binding mismatch → not licensed → grants nothing (deny-by-default).
        $this->assertInstanceOf(LicenseCapabilityGate::class, $gate);
        $this->assertFalse($gate->allows(Capabilities::SSO));
        $this->assertFalse($gate->allows(Capabilities::SUPPORT));
    }
}
