<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\CurrentUser;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Enums\WebhookEvent;
use App\Webhooks\Exceptions\UnsafeWebhookUrl;
use App\Webhooks\WebhookEndpointRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Console CRUD for outbound webhook endpoints (Settings → Webhooks). Reads carry `settings:read`,
 * writes `settings:manage` (enforced on the routes). Thin over {@see WebhookEndpointRegistry}: it
 * validates HTTP input, delegates, and maps the result to a view/redirect — the SSRF guard, secret
 * minting, and delivery live in the domain services. The signing secret is shown ONCE by rendering
 * it directly into the POST response (never flashed through the session), reusing the API-token
 * show-once pattern.
 */
class WebhookEndpointController extends Controller
{
    public function __construct(private readonly CurrentUser $current) {}

    public function index(): View
    {
        return view('billing.settings.webhooks.index', $this->indexData());
    }

    public function create(): View
    {
        return view('billing.settings.webhooks.form', [
            'activeArea' => 'settings',
            'activeNav' => 'webhooks',
            'endpoint' => null,
            'catalog' => WebhookEvent::grouped(),
            'selected' => [],
        ]);
    }

    public function store(Request $request, WebhookEndpointRegistry $registry): View|RedirectResponse
    {
        $data = $this->validated($request);

        try {
            ['endpoint' => $endpoint, 'secret' => $secret] = $registry->register(
                $data['url'],
                $data['event_types'],
                $data['description'],
                $this->current->user()?->sub,
            );
        } catch (UnsafeWebhookUrl $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        // Show the signing secret ONCE, rendered directly into this response (SEC-3) — never
        // flashed through the session store. A later GET will not show it again.
        return view('billing.settings.webhooks.index', $this->indexData([
            'id' => $endpoint->id,
            'secret' => $secret,
            'label' => 'Endpoint registered',
        ]));
    }

    public function edit(WebhookEndpoint $webhookEndpoint): View
    {
        return view('billing.settings.webhooks.form', [
            'activeArea' => 'settings',
            'activeNav' => 'webhooks',
            'endpoint' => $webhookEndpoint,
            'catalog' => WebhookEvent::grouped(),
            'selected' => $webhookEndpoint->event_types,
        ]);
    }

    public function update(Request $request, WebhookEndpoint $webhookEndpoint, WebhookEndpointRegistry $registry): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $registry->update($webhookEndpoint, $data['url'], $data['event_types'], $data['description']);
        } catch (UnsafeWebhookUrl $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        return redirect()
            ->route('billing.settings.webhooks')
            ->with('status', 'Endpoint updated.');
    }

    public function rotate(WebhookEndpoint $webhookEndpoint, WebhookEndpointRegistry $registry): View
    {
        $secret = $registry->rotateSecret($webhookEndpoint);

        return view('billing.settings.webhooks.index', $this->indexData([
            'id' => $webhookEndpoint->id,
            'secret' => $secret,
            'label' => 'Signing secret rotated',
        ]));
    }

    public function activate(WebhookEndpoint $webhookEndpoint, WebhookEndpointRegistry $registry): RedirectResponse
    {
        $registry->setActive($webhookEndpoint, true);

        return redirect()->route('billing.settings.webhooks')->with('status', 'Endpoint activated.');
    }

    public function deactivate(WebhookEndpoint $webhookEndpoint, WebhookEndpointRegistry $registry): RedirectResponse
    {
        $registry->setActive($webhookEndpoint, false);

        return redirect()->route('billing.settings.webhooks')->with('status', 'Endpoint deactivated — it will receive no further events.');
    }

    public function destroy(WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        $webhookEndpoint->delete();

        return redirect()->route('billing.settings.webhooks')->with('status', 'Endpoint deleted.');
    }

    public function test(WebhookEndpoint $webhookEndpoint, WebhookEndpointRegistry $registry): RedirectResponse
    {
        $registry->sendTest($webhookEndpoint);

        return redirect()
            ->route('billing.settings.webhooks.show', $webhookEndpoint)
            ->with('status', 'Test ping queued — see the delivery log below for the result.');
    }

    public function show(WebhookEndpoint $webhookEndpoint): View
    {
        return view('billing.settings.webhooks.show', [
            'activeArea' => 'settings',
            'activeNav' => 'webhooks',
            'endpoint' => $webhookEndpoint,
            'deliveries' => $webhookEndpoint->deliveries()->latest()->limit(100)->get(),
        ]);
    }

    public function redeliver(WebhookEndpoint $webhookEndpoint, WebhookDelivery $delivery, WebhookEndpointRegistry $registry): RedirectResponse
    {
        abort_unless($delivery->endpoint_id === $webhookEndpoint->id, 404);

        $registry->redeliver($delivery);

        return redirect()
            ->route('billing.settings.webhooks.show', $webhookEndpoint)
            ->with('status', 'Delivery re-queued.');
    }

    /**
     * @return array{url: string, description: string|null, event_types: list<string>}
     */
    private function validated(Request $request): array
    {
        $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:255'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string'],
        ]);

        $eventTypes = $request->input('event_types');
        $description = $request->filled('description') ? $request->string('description')->toString() : null;

        return [
            'url' => $request->string('url')->toString(),
            'description' => $description,
            'event_types' => WebhookEvent::sanitize(is_array($eventTypes) ? array_values($eventTypes) : []),
        ];
    }

    /**
     * @param  array{id: string, secret: string, label: string}|null  $revealed
     * @return array<string, mixed>
     */
    private function indexData(?array $revealed = null): array
    {
        return [
            'activeArea' => 'settings',
            'activeNav' => 'webhooks',
            'endpoints' => WebhookEndpoint::query()->withCount('deliveries')->latest()->get(),
            'catalog' => WebhookEvent::grouped(),
            'revealed' => $revealed,
        ];
    }
}
