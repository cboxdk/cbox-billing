<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\CurrentUser;
use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Environments\Contracts\DestroysEnvironments;
use App\Billing\Environments\Contracts\ResetsEnvironments;
use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Environments\Exceptions\EnvironmentCloneException;
use App\Billing\Environments\Exceptions\EnvironmentProtectedException;
use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Models\Environment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The console's Environments management area (Settings). Lists the named planes (type, active,
 * gateway-key mode, protected, whether per-environment gateway keys are set), creates a sandbox
 * (optionally cloning an existing plane's config), resets a sandbox's book, and destroys a sandbox.
 * Thin over the lifecycle contracts — production protection and the data-teardown scope live in the
 * services, never here. The destructive actions carry a confirmed framing in the view and are
 * refused for production server-side.
 */
class EnvironmentsController extends Controller
{
    public function __construct(
        private readonly CurrentUser $current,
        private readonly EnvironmentRegistry $registry,
        private readonly EnvironmentGatewayStore $gateways,
    ) {}

    /** The environments list + the create form. */
    public function index(): View
    {
        $active = $this->current->activeEnvironmentKey();

        $environments = array_map(function (Environment $environment): array {
            return [
                'key' => $environment->key,
                'name' => $environment->name,
                'type' => $environment->type->value,
                'protected' => $environment->protected,
                'gateway_key_mode' => $environment->gateway_key_mode->value,
                'has_gateway_keys' => $this->gateways->forEnvironment($environment->key) !== null,
            ];
        }, $this->registry->all());

        return view('billing.settings.environments', [
            'activeArea' => 'settings',
            'activeNav' => 'environments',
            'environments' => $environments,
            'activeEnvironment' => $active,
        ]);
    }

    /** Create a sandbox (optionally cloning a source plane's config), then switch the console to it. */
    public function store(Request $request, CreatesEnvironments $creator): RedirectResponse
    {
        $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:120'],
            'clone_from' => ['nullable', 'string', 'max:40'],
        ]);

        $cloneFrom = null;

        if ($request->filled('clone_from')) {
            $cloneFrom = $this->registry->find($request->string('clone_from')->toString());

            if ($cloneFrom === null || ! $cloneFrom->exists) {
                return back()->with('error', 'Unknown source environment to clone from — no change.');
            }
        }

        try {
            $result = $creator->create(
                key: $request->string('key')->toString(),
                name: $request->filled('name') ? $request->string('name')->toString() : null,
                cloneFrom: $cloneFrom,
                withToken: false,
            );
        } catch (EnvironmentCloneException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->current->setActiveEnvironment($result->environment->key);

        return redirect()->route('billing.environments')->with('status', sprintf(
            'Created sandbox “%s”%s. You are now on the new plane (empty book, test gateway keys).',
            $result->environment->key,
            $result->cloned ? sprintf(' as a clone of “%s”', (string) $cloneFrom?->key) : '',
        ));
    }

    /** Reset a sandbox's transactional book (keeping its config). Never resets production. */
    public function reset(Request $request, string $key, ResetsEnvironments $resetter): RedirectResponse
    {
        $environment = $this->registry->find($key);

        if ($environment === null || ! $environment->exists) {
            return back()->with('error', 'Unknown environment — no change.');
        }

        try {
            $result = $resetter->reset($environment);
        } catch (EnvironmentProtectedException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing.environments')->with('status', sprintf(
            'Reset “%s” — wiped %d row(s) of transactional data; its config survives.',
            $environment->key,
            $result->totalDeleted(),
        ));
    }

    /** Destroy a sandbox and all its plane data. Never destroys production. */
    public function destroy(string $key, DestroysEnvironments $destroyer): RedirectResponse
    {
        $environment = $this->registry->find($key);

        if ($environment === null || ! $environment->exists) {
            return back()->with('error', 'Unknown environment — no change.');
        }

        // If the console is currently on the plane being destroyed, fall back to production first.
        if ($this->current->activeEnvironmentKey() === $environment->key) {
            $this->current->setActiveEnvironment(Environment::PRODUCTION);
        }

        try {
            $result = $destroyer->destroy($environment);
        } catch (EnvironmentProtectedException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing.environments')->with('status', sprintf(
            'Destroyed “%s” — the plane and all %d of its rows are gone.',
            $key,
            $result->totalDeleted(),
        ));
    }
}
