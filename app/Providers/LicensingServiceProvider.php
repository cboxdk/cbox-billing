<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Licensing\Contracts\IssuesLicenses;
use App\Billing\Licensing\Contracts\LicenseRevocationRegistry;
use App\Billing\Licensing\DatabaseIssuedLicenseStore;
use App\Billing\Licensing\DatabaseRevocationRegistry;
use App\Billing\Licensing\LicenseIssuanceService;
use Cbox\Billing\Licensing\Contracts\IssuedLicenseStore;
use Cbox\Billing\Licensing\Contracts\RevocationRegistry;
use Cbox\License\Contracts\LicenseIssuer;
use Cbox\License\Contracts\RevocationListIssuer;
use Cbox\License\Ed25519LicenseIssuer;
use Cbox\License\Ed25519RevocationListIssuer;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
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
        ));

        $this->app->singleton(DatabaseIssuedLicenseStore::class, static fn (Application $app): DatabaseIssuedLicenseStore => $app->make(IssuedLicenseStore::class));

        $this->app->singleton(LicenseRevocationRegistry::class, static fn (Application $app): DatabaseRevocationRegistry => new DatabaseRevocationRegistry(
            $app->make('db')->connection(),
        ));

        // The engine's RevocationPublisher reads the same durable registry.
        $this->app->singleton(RevocationRegistry::class, static fn (Application $app): DatabaseRevocationRegistry => $app->make(LicenseRevocationRegistry::class));

        $this->app->singleton(IssuesLicenses::class, LicenseIssuanceService::class);
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
}
