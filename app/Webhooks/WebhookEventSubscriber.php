<?php

declare(strict_types=1);

namespace App\Webhooks;

/**
 * The single listener bound to every source domain event (engine + app). It resolves the event to
 * its outbound shape via the {@see EventPayloadMapper} and hands it to the {@see WebhookDispatcher}
 * for idempotent, queued fan-out. An event the catalog does not map is silently ignored
 * (deny-by-default), so binding a new source event without a mapper entry never delivers garbage.
 */
readonly class WebhookEventSubscriber
{
    public function __construct(
        private EventPayloadMapper $mapper,
        private WebhookDispatcher $dispatcher,
    ) {}

    public function handle(object $event): void
    {
        $resolved = $this->mapper->resolve($event);

        if ($resolved === null) {
            return;
        }

        $this->dispatcher->dispatch($resolved);
    }
}
