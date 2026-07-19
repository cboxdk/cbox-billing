<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Licensing\Contracts\IssuesLicenses;
use App\Billing\Licensing\Contracts\LicenseRevocationRegistry;
use App\Billing\Licensing\DatabaseIssuedLicenseStore;
use App\Billing\Licensing\DatabaseRevocationRegistry;
use App\Billing\Licensing\LicenseIssuanceService;
use App\Billing\Mode\BillingContext;
use Cbox\Billing\Licensing\Contracts\IssuedLicenseStore;
use Cbox\Billing\Licensing\Contracts\RevocationRegistry;
use Cbox\License\Contracts\CapabilityGate;
use Cbox\License\Contracts\LicenseIssuer;
use Cbox\License\Contracts\RevocationListIssuer;
use Cbox\License\DenyingCapabilityGate;
use Cbox\License\Ed25519LicenseIssuer;
use Cbox\License\Ed25519LicenseVerifier;
use Cbox\License\Ed25519RevocationListIssuer;
use Cbox\License\LicenseCapabilityGate;
use Cbox\License\ValueObjects\VerificationContext;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Wires the on-prem license ISSUER to this app's durable foundation. Two responsibilities,
 * both thin container bindings:
 *
 *  1. Bind the crypto core's key holders from config — the {@see LicenseIssuer} and
 *     {@see RevocationListIssuer} that hold the Ed25519 PRIVATE key. The engine's licensing
 *     module deliberately leaves these unbound (the key is a host secret); we construct
 *     them from `billing.licensing.signing_key`. The binding is LAZY and throws a clear
 *     operator error only when licensing is actually used, so an app with no key still
 *     boots and runs everything else.
 *  2. Rebind the engine's in-memory licensing ports — the issued-license store and the
 *     revocation registry — to their database-backed implementations, so minted licenses
 *     and revocations survive a restart. The engine already binds the profile resolver
 *     from `billing.licensing.profiles` (deny-by-default), so that is left untouched.
 *  3. Bind the single {@see CapabilityGate} that a COMPOSED deployment (this base app plus
 *     private commercial plugins baked into the cloud image) reads to decide whether a paid
 *     capability is unlocked. Deny-by-default: with no consume-license configured every
 *     capability stays locked (the free tier). When `consume_key` IS set, its artifact is
 *     verified offline against the issuer public key and a {@see LicenseCapabilityGate} over
 *     the fresh result is bound — so plugins unlock exactly the license's entitlements.
 *
 * Two DISTINCT keys live here: this app is the license ISSUER (it signs licenses for
 * customers with `signing_key`); the `consume_key` is the license THIS deployment installs
 * to unlock its OWN commercial plugins. They are separate concerns and separate keys.
 *
 * The signing key is never logged and never leaves config; only the public key is ever
 * displayed.
 */
class LicensingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LicenseIssuer::class, static fn (Application $app): Ed25519LicenseIssuer => new Ed25519LicenseIssuer(
            self::signingKey($app->make(Config::class)),
        ));

        $this->app->singleton(RevocationListIssuer::class, static fn (Application $app): Ed25519RevocationListIssuer => new Ed25519RevocationListIssuer(
            self::signingKey($app->make(Config::class)),
        ));

        $this->app->singleton(IssuedLicenseStore::class, static fn (Application $app): DatabaseIssuedLicenseStore => new DatabaseIssuedLicenseStore(
            $app->make('db')->connection(),
            $app->make(BillingContext::class),
        ));

        $this->app->singleton(DatabaseIssuedLicenseStore::class, static fn (Application $app): DatabaseIssuedLicenseStore => $app->make(IssuedLicenseStore::class));

        $this->app->singleton(LicenseRevocationRegistry::class, static fn (Application $app): DatabaseRevocationRegistry => new DatabaseRevocationRegistry(
            $app->make('db')->connection(),
        ));

        // The engine's RevocationPublisher reads the same durable registry.
        $this->app->singleton(RevocationRegistry::class, static fn (Application $app): DatabaseRevocationRegistry => $app->make(LicenseRevocationRegistry::class));

        $this->app->singleton(IssuesLicenses::class, LicenseIssuanceService::class);

        // The single seam every commercial plugin reads. Resolved LAZILY (a singleton
        // closure only runs on first resolution), so an unconfigured deployment never
        // verifies at boot and simply denies by default.
        $this->app->singleton(CapabilityGate::class, static function (Application $app): CapabilityGate {
            $config = $app->make(Config::class);
            $consumeKey = $config->get('billing.licensing.consume_key');

            // Deny-by-default: no consume-license → the free tier, and no plugin
            // capability unlocks by omission.
            if (! is_string($consumeKey) || $consumeKey === '') {
                return new DenyingCapabilityGate;
            }

            // Verify the installed license offline against the ISSUER public key, reusing
            // the same grace/skew the verifier deployment honours. An unlicensed result
            // (expired-beyond-grace, wrong deployment, bad signature, …) naturally grants
            // nothing.
            $verifier = new Ed25519LicenseVerifier(
                self::stringConfig($config, 'billing.licensing.public_key'),
                self::intConfig($config, 'billing.licensing.grace_seconds', 0),
                self::intConfig($config, 'billing.licensing.clock_skew_seconds', 60),
            );

            $result = $verifier->verify($consumeKey, new VerificationContext(
                self::stringConfig($config, 'billing.licensing.deployment_id'),
                null,
                Carbon::now()->toDateTimeImmutable(),
            ));

            return new LicenseCapabilityGate($result);
        });
    }

    /**
     * The base64 Ed25519 private key that signs licenses and revocation lists. Absent it,
     * licensing is inert: resolving a signer surfaces a clear operator error (never at
     * boot, and never leaking the key), so the app runs fine until licensing is used.
     */
    private static function signingKey(Config $config): string
    {
        $key = $config->get('billing.licensing.signing_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException(
                'No license signing key configured. Set CBOX_LICENSE_SIGNING_KEY to a base64 Ed25519 private key '
                .'(generate one with `php artisan billing:license-keygen`) before issuing or revoking licenses.',
            );
        }

        return $key;
    }

    /**
     * A string config value, or '' when unset/non-string. An empty deployment id or
     * public key naturally yields a denying verification result (grants nothing).
     */
    private static function stringConfig(Config $config, string $key): string
    {
        $value = $config->get($key);

        return is_string($value) ? $value : '';
    }

    /**
     * An int config value, or the supplied default when unset/non-int.
     */
    private static function intConfig(Config $config, string $key, int $default): int
    {
        $value = $config->get($key);

        return is_int($value) ? $value : $default;
    }
}
