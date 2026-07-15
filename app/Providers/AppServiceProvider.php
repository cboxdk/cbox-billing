<?php

namespace App\Providers;

use App\Auth\CboxIdOidc;
use App\Auth\Contracts\IdentityProvider;
use App\Auth\CurrentUser;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View as ViewContract;
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
        // Make the signed-in user available to the app shell.
        View::composer('layouts.app', function (ViewContract $view): void {
            $view->with('currentUser', $this->app->make(CurrentUser::class)->user());
        });
    }
}
