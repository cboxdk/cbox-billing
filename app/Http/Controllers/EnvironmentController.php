<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\CurrentUser;
use App\Billing\Environments\Contracts\ClonesEnvironments;
use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Environments\Exceptions\EnvironmentCloneException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The console's environment-management actions. Thin over the {@see ClonesEnvironments} contract
 * and the {@see EnvironmentRegistry}: it resolves the chosen source plane (default production),
 * hands off the deep config copy to the service, and — on success — switches the console into the
 * fresh clone so the operator lands in the isolated dataset they just created. All orchestration,
 * relationship preservation and secret-blanking live in the service, never here.
 */
class EnvironmentController extends Controller
{
    public function __construct(
        private readonly CurrentUser $current,
        private readonly EnvironmentRegistry $environments,
    ) {}

    /** Clone the chosen source environment's config into a new sandbox plane, then switch to it. */
    public function clone(Request $request, ClonesEnvironments $cloner): RedirectResponse
    {
        $request->validate([
            'source' => ['required', 'string', 'max:40'],
            'new_key' => ['required', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $source = $this->environments->find($request->string('source')->toString());

        if ($source === null || ! $source->exists) {
            return redirect()->back()->with('status', 'Unknown source environment — no change.');
        }

        try {
            $target = $cloner->clone(
                $source,
                $request->string('new_key')->toString(),
                $request->filled('name') ? $request->string('name')->toString() : null,
            );
        } catch (EnvironmentCloneException $e) {
            return redirect()->back()->with('status', $e->getMessage());
        }

        $this->current->setActiveEnvironment($target->key);

        return redirect()->back()->with('status', sprintf(
            'Cloned “%s” → “%s”. Config copied; you are now on the new sandbox (empty book, test gateway keys).',
            $source->key,
            $target->key,
        ));
    }
}
