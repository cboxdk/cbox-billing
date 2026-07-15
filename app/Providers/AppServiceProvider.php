<?php

namespace App\Providers;

use App\Auth\CboxIdOidc;
use App\Auth\Contracts\IdentityProvider;
use App\Auth\CurrentUser;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(IdentityProvider::class, fn ($app): CboxIdOidc => new CboxIdOidc(
            config: $app['config']->get('services.cbox_id'),
            cache: $app->make(Cache::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Make the signed-in user available to the app shell.
        View::composer('layouts.app', function ($view): void {
            $view->with('currentUser', $this->app->make(CurrentUser::class)->user());
        });
    }
}
