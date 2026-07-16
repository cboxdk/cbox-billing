<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Enforcement\Upgrade\UpgradeGate;
use App\Http\View\NavigationComposer;
use App\Platform\ConsoleCurrentContext;
use App\Platform\ConsoleNav;
use Cbox\Console\Kit\Contracts\CurrentContext;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Support\ServiceProvider;

/**
 * Wires this app into the console-kit plugin socket: it binds the {@see CurrentContext}
 * to this app's auth, seeds the base navigation into the shared {@see Console} nav
 * registry, and registers the base app's own features. The shell renders from the
 * registry (see {@see NavigationComposer}), so a private commercial plugin
 * can add areas/pages/slots/dashboard cards purely by being installed — zero edits here.
 *
 * A console-kit `feature` is a hard PRESENCE gate: a page (or area) is hidden, and its
 * routes 404, unless the feature is active. That is DISTINCT from the entitlement/upgrade
 * soft-lock ({@see UpgradeGate}), which shows the page
 * but blocks the action when the plan does not entitle it. "Plugin installed" is never
 * conflated with "plan entitles".
 */
class ConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Let plugins resolve the current org/user through console-kit's CurrentContext.
        $this->app->bind(CurrentContext::class, ConsoleCurrentContext::class);
    }

    public function boot(): void
    {
        // The base app's own features. Registered always-on so the console renders
        // identically to before; a stripped deployment (or the -cloud overlay) can flip
        // one off and its gated nav pages + routes vanish. Deny-by-default in the socket
        // means a feature a plugin never registers is simply absent.
        Console::features()->register('licenses', true);

        // Seed the base navigation into the shared registry a plugin also extends.
        ConsoleNav::seed(Console::nav());
    }
}
