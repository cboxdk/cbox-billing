<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\CurrentUser;
use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Environments\Promotion\Contracts\PromotesConfig;
use App\Billing\Environments\Promotion\Exceptions\PromotionException;
use App\Billing\Environments\Promotion\PromotionGroup;
use App\Billing\Environments\Promotion\PromotionSelection;
use App\Billing\Environments\Promotion\ValueObjects\PromotionPreview;
use App\Models\Environment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The console "Promote" screen: publish SELECTED config from one environment to another (typically
 * a sandbox → production) with a diff-preview and a confirm. Thin over the {@see PromotesConfig}
 * contract and the {@see EnvironmentRegistry}:
 *
 *  - {@see form()} renders the picker (source defaults to the current non-production plane, target
 *    defaults to production);
 *  - {@see preview()} recomputes the write-free diff and re-renders the screen with it (a POST that
 *    writes nothing — its route ends `.preview` so the audit trail ignores it);
 *  - {@see apply()} publishes on confirm, and reports created/updated/unchanged or the blocking
 *    conflicts that refused the write.
 *
 * All natural-key matching, diffing, relationship remapping and audit logging live in the service.
 */
class EnvironmentPromotionController extends Controller
{
    public function __construct(
        private readonly EnvironmentRegistry $environments,
        private readonly CurrentUser $current,
    ) {}

    /** The picker, with sensible source/target defaults. */
    public function form(Request $request): View
    {
        return $this->screen($this->defaultSource(), Environment::PRODUCTION, [], '', null);
    }

    /** Recompute and render the write-free diff for the chosen source/target/selection. */
    public function preview(Request $request, PromotesConfig $promoter): View|RedirectResponse
    {
        [$source, $target, $selection, $groups, $objects] = $this->resolve($request);

        if ($source === null || $target === null) {
            return redirect()->route('billing.environment.promote')->with('error', 'Pick a valid source and target environment.');
        }

        if ($source->key === $target->key) {
            return $this->screen($source->key, $target->key, $groups, $objects, null)
                ->with('error', 'Source and target must be different environments.');
        }

        $preview = $promoter->preview($source, $target, $selection);

        return $this->screen($source->key, $target->key, $groups, $objects, $preview);
    }

    /** Publish the selection into the target on confirm. */
    public function apply(Request $request, PromotesConfig $promoter): RedirectResponse
    {
        [$source, $target, $selection, $groups, $objects] = $this->resolve($request);

        if ($source === null || $target === null) {
            return redirect()->route('billing.environment.promote')->with('error', 'Pick a valid source and target environment.');
        }

        try {
            $result = $promoter->promote($source, $target, $selection);
        } catch (PromotionException $e) {
            return redirect()
                ->route('billing.environment.promote', ['source' => $source->key, 'target' => $target->key, 'groups' => $groups, 'objects' => $objects])
                ->with('error', $e->getMessage());
        }

        return redirect()->route('billing.environment.promote')->with('status', sprintf(
            'Published “%s” → “%s”: %d created, %d updated, %d unchanged%s.',
            $result->source(),
            $result->target(),
            $result->created,
            $result->updated,
            $result->unchanged,
            $result->wroteAnything() ? '' : ' (already in sync — nothing to do)',
        ));
    }

    /**
     * Resolve the request into typed inputs.
     *
     * @return array{0: Environment|null, 1: Environment|null, 2: PromotionSelection, 3: list<string>, 4: string}
     */
    private function resolve(Request $request): array
    {
        $request->validate([
            'source' => ['required', 'string', 'max:40'],
            'target' => ['required', 'string', 'max:40'],
            'groups' => ['array'],
            'groups.*' => ['string', 'max:40'],
            'objects' => ['nullable', 'string', 'max:4000'],
        ]);

        $source = $this->environments->find($request->string('source')->toString());
        $target = $this->environments->find($request->string('target')->toString());

        $groupsInput = $request->input('groups', []);
        $groups = is_array($groupsInput)
            ? array_values(array_filter(array_map(static fn ($g): string => is_string($g) ? $g : '', $groupsInput), static fn (string $s): bool => $s !== ''))
            : [];

        $objects = $request->string('objects')->toString();
        $objectTokens = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $objects) ?: []), static fn (string $s): bool => $s !== ''));

        $selection = PromotionSelection::fromInput($groups, $objectTokens);

        return [
            $source !== null && $source->exists ? $source : null,
            $target !== null && $target->exists ? $target : null,
            $selection,
            $groups,
            $objects,
        ];
    }

    /**
     * @param  list<string>  $groups
     */
    private function screen(string $source, string $target, array $groups, string $objects, ?PromotionPreview $preview): View
    {
        return view('billing.promote', [
            'activeArea' => 'settings',
            'activeNav' => 'promote',
            'planes' => $this->environments->all(),
            'sourceKey' => $source,
            'targetKey' => $target,
            'selectedGroups' => $groups,
            'objectsInput' => $objects,
            'groups' => PromotionGroup::cases(),
            'preview' => $preview,
        ]);
    }

    /** The default source: the current console plane when it is a sandbox, else the default sandbox. */
    private function defaultSource(): string
    {
        $active = $this->current->activeEnvironmentKey();
        if ($active !== '' && $active !== Environment::PRODUCTION) {
            return $active;
        }

        foreach ($this->environments->all() as $environment) {
            if (! $environment->isProduction()) {
                return $environment->key;
            }
        }

        return Environment::SANDBOX;
    }
}
