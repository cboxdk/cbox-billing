<?php

namespace App\Providers;

use App\Auth\CboxIdOidc;
use App\Auth\Contracts\IdentityProvider;
use App\Auth\CurrentUser;
use App\Http\View\NavigationComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(IdentityProvider::class, function (Application $app): CboxIdOidc {
            $config = $app->make(Config::class);

            return new CboxIdOidc(
                config: [
                    'issuer' => self::nullableString($config->get('services.cbox_id.issuer')),
                    'client_id' => self::nullableString($config->get('services.cbox_id.client_id')),
                    'client_secret' => self::nullableString($config->get('services.cbox_id.client_secret')),
                    'redirect' => self::nullableString($config->get('services.cbox_id.redirect')),
                    'scopes' => self::nullableString($config->get('services.cbox_id.scopes')) ?? 'openid profile email',
                ],
                cache: $app->make(Cache::class),
            );
        });
    }

    /** A config value as a non-empty string, or null when unset/blank. */
    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRateLimiters();

        // Make the signed-in user available to the app shell.
        View::composer('layouts.app', function (ViewContract $view): void {
            $view->with('currentUser', $this->app->make(CurrentUser::class)->user());
        });

        // Overlay live, database-derived counts onto the navigation IA.
        View::composer('layouts.app', NavigationComposer::class);
    }

    /**
     * The named, config-driven API rate limiters applied as `throttle:cbox-*` on the
     * route groups (see routes/api.php + bootstrap/app.php). Each is keyed per bearer
     * token (the caller's IP is the fallback for a token-less request), so one tenant's
     * traffic can never exhaust another's budget.
     */
    private function registerRateLimiters(): void
    {
        $limits = $this->app->make(Config::class)->get('billing.rate_limits', []);
        $limits = is_array($limits) ? $limits : [];

        $enforcement = self::intLimit($limits['enforcement'] ?? null, 600);
        $management = self::intLimit($limits['management'] ?? null, 60);
        $webhook = self::intLimit($limits['webhook'] ?? null, 120);

        RateLimiter::for('cbox-enforcement', static fn (Request $request): Limit => Limit::perMinute($enforcement)->by(self::throttleKey($request)));

        RateLimiter::for('cbox-management', static fn (Request $request): Limit => Limit::perMinute($management)->by(self::throttleKey($request)));

        RateLimiter::for('cbox-webhook', static fn (Request $request): Limit => Limit::perMinute($webhook)->by($request->ip() ?? 'webhook'));
    }

    /** The per-caller throttle key: the (hashed) bearer token, or the client IP. */
    private static function throttleKey(Request $request): string
    {
        $bearer = $request->bearerToken();

        return $bearer !== null && $bearer !== ''
            ? 'token:'.hash('sha256', $bearer)
            : 'ip:'.($request->ip() ?? 'unknown');
    }

    /** A positive integer limit, or the fallback when the config value is unusable. */
    private static function intLimit(mixed $value, int $fallback): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }
}
