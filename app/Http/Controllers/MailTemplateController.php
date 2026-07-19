<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Notifications\BillingNotifier;
use App\Billing\Notifications\Contracts\ComposesTransactionalMail;
use App\Billing\Notifications\Contracts\ResolvesMailTemplates;
use App\Billing\Notifications\LocaleResolver;
use App\Billing\Notifications\MailEventType;
use App\Models\MailTemplate;
use App\Models\SellerEntity;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Emails / Notifications settings surface (Wave 5) — thin HTTP over the transactional-mail
 * system. Lists every event type × locale × seller with its resolved source (shipped default
 * vs override), edits a template with a live server-rendered preview of the ACTUAL branded
 * email, resets an override back to the shipped default, and sends a test email through the
 * real notifier (honouring test-mode capture). Reads carry `settings:read`; writes carry
 * `settings:manage`.
 *
 * The preview and every render go through the same sandboxed pipeline the lifecycle mail uses,
 * so a DB-authored template is never evaluated as code and what the operator previews is
 * exactly what a customer receives.
 */
class MailTemplateController extends Controller
{
    public function index(Request $request, ResolvesMailTemplates $resolver, LocaleResolver $locales): View
    {
        $seller = $this->sellerScope($request);
        $localeCodes = array_keys($locales->supported());

        $rows = [];
        foreach (MailEventType::all() as $event) {
            $cells = [];
            foreach ($localeCodes as $locale) {
                $resolved = $resolver->resolve($event, $locale, $seller?->id);
                $cells[$locale] = [
                    'source' => $resolved->source,
                    'has_override' => $this->overrideExists($event, $locale, $seller?->id),
                    'edit_url' => route('billing.settings.emails.edit', [$event->value, 'locale' => $locale, 'seller' => $seller?->id]),
                ];
            }

            $rows[] = ['event' => $event, 'cells' => $cells];
        }

        return view('billing.settings.emails.index', [
            'activeArea' => 'settings',
            'activeNav' => 'emails',
            'rows' => $rows,
            'locales' => $locales->supported(),
            'sellers' => $this->sellerOptions(),
            'sellerScope' => $seller,
        ]);
    }

    public function edit(Request $request, string $eventType, ResolvesMailTemplates $resolver, LocaleResolver $locales): View
    {
        $event = $this->event($eventType);
        $seller = $this->sellerScope($request);
        $locale = $this->localeFromRequest($request, $locales);

        $override = $this->override($event, $locale, $seller?->id);
        $resolved = $resolver->resolve($event, $locale, $seller?->id);

        return view('billing.settings.emails.edit', [
            'activeArea' => 'settings',
            'activeNav' => 'emails',
            'event' => $event,
            'locale' => $locale,
            'sellerScope' => $seller,
            'sellers' => $this->sellerOptions(),
            'locales' => $locales->supported(),
            // Seed the editor with the override if one exists, else the resolved default so the
            // operator starts from the real shipped copy rather than a blank page.
            'subject' => old('subject', $override !== null ? $override->subject : $resolved->subject),
            'body' => old('body', $override !== null ? $override->body : $resolved->body),
            'hasOverride' => $override !== null,
            'resolvedSource' => $resolved->source,
            'variables' => $event->variables(),
        ]);
    }

    public function update(Request $request, string $eventType, LocaleResolver $locales): RedirectResponse
    {
        $event = $this->event($eventType);

        $request->validate([
            'locale' => ['required', 'string', 'max:12'],
            'seller' => ['nullable', 'string', 'exists:seller_entities,id'],
            'subject' => ['required', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:30000'],
        ]);

        $locale = $locales->normalize($request->string('locale')->toString());

        if ($locale === null || ! $locales->isSupported($locale)) {
            return back()->withInput()->with('error', 'Unsupported locale.');
        }

        $seller = $request->string('seller')->toString();
        $sellerId = $seller !== '' ? $seller : null;

        MailTemplate::query()->updateOrCreate(
            ['event_type' => $event->value, 'locale' => $locale, 'seller_entity_id' => $sellerId],
            ['subject' => $request->string('subject')->toString(), 'body' => $request->string('body')->toString()],
        );

        return redirect()
            ->route('billing.settings.emails.edit', [$event->value, 'locale' => $locale, 'seller' => $seller])
            ->with('status', sprintf('“%s” (%s) template saved.', $event->label(), $locale));
    }

    public function reset(Request $request, string $eventType, LocaleResolver $locales): RedirectResponse
    {
        $event = $this->event($eventType);
        $locale = $this->localeFromRequest($request, $locales);
        $seller = $this->sellerScope($request);

        $this->override($event, $locale, $seller?->id)?->delete();

        return redirect()
            ->route('billing.settings.emails.edit', [$event->value, 'locale' => $locale, 'seller' => $seller?->id])
            ->with('status', sprintf('“%s” (%s) reset to the shipped default.', $event->label(), $locale));
    }

    /**
     * Live server-rendered preview of the ACTUAL branded email. A POST carrying the draft
     * subject/body previews the unsaved editor content; a GET previews the resolved (saved)
     * template. Returns a full HTML document rendered through the real pipeline for the chosen
     * seller + locale + sample data, to be shown inside a sandboxed iframe. Never executes
     * DB-authored code (the sandboxed renderer) and never persists anything.
     */
    public function preview(Request $request, string $eventType, ComposesTransactionalMail $composer, LocaleResolver $locales): Response
    {
        $event = $this->event($eventType);
        $locale = $this->localeFromRequest($request, $locales);
        $seller = $this->sellerScope($request);

        if ($request->isMethod('post')) {
            $subject = $request->string('subject')->toString();
            $body = $request->string('body')->toString();
            $rendered = $composer->composeDraft($event, $subject, $body, $event->sampleVariables(), $seller?->id, $locale);
        } else {
            $rendered = $composer->compose($event, $event->sampleVariables(), $seller?->id, $locale);
        }

        // The preview is our own escaped HTML; the sandboxed iframe additionally blocks scripts.
        return response($rendered->html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'");
    }

    public function testSend(Request $request, string $eventType, BillingNotifier $notifier, LocaleResolver $locales): RedirectResponse
    {
        $event = $this->event($eventType);

        $request->validate([
            'locale' => ['required', 'string', 'max:12'],
            'seller' => ['nullable', 'string', 'exists:seller_entities,id'],
            'recipient' => ['required', 'email', 'max:254'],
        ]);

        $locale = $locales->normalize($request->string('locale')->toString()) ?? $locales->fallback();
        $seller = $request->string('seller')->toString();
        $sellerId = $seller !== '' ? $seller : null;
        $recipient = $request->string('recipient')->toString();

        $captured = $notifier->sendTest($event, $sellerId, $locale, $recipient);

        $message = $captured
            ? sprintf('Test email captured (not delivered) — test mode is on. “%s” to %s.', $event->label(), $recipient)
            : sprintf('Test email sent — “%s” to %s.', $event->label(), $recipient);

        return redirect()
            ->route('billing.settings.emails.edit', [$event->value, 'locale' => $locale, 'seller' => $seller])
            ->with('status', $message);
    }

    private function event(string $eventType): MailEventType
    {
        return MailEventType::tryFrom($eventType) ?? abort(404);
    }

    /** The selling entity the current view is scoped to, or null for the account-wide default. */
    private function sellerScope(Request $request): ?SellerEntity
    {
        $id = $request->string('seller')->toString();

        if ($id === '') {
            return null;
        }

        return SellerEntity::query()->whereKey($id)->first();
    }

    private function localeFromRequest(Request $request, LocaleResolver $locales): string
    {
        $locale = $locales->normalize($request->string('locale')->toString());

        return $locale !== null && $locales->isSupported($locale) ? $locale : $locales->fallback();
    }

    private function override(MailEventType $event, string $locale, ?string $sellerId): ?MailTemplate
    {
        $query = MailTemplate::query()
            ->where('event_type', $event->value)
            ->where('locale', $locale);

        $query = $sellerId === null || $sellerId === ''
            ? $query->whereNull('seller_entity_id')
            : $query->where('seller_entity_id', $sellerId);

        return $query->first();
    }

    private function overrideExists(MailEventType $event, string $locale, ?string $sellerId): bool
    {
        return $this->override($event, $locale, $sellerId) !== null;
    }

    /** @return array<string, string> id → label, plus the account-wide sentinel ('' => …). */
    private function sellerOptions(): array
    {
        $options = ['' => 'Account-wide (all sellers)'];

        foreach (SellerEntity::query()->whereNull('archived_at')->orderBy('legal_name')->get() as $seller) {
            $options[$seller->id] = $seller->legal_name;
        }

        return $options;
    }
}
