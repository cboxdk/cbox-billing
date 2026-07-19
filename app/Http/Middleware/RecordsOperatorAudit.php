<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\Support\AuditRequestTally;
use App\Billing\Audit\ValueObjects\AuditTarget;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CENTRAL recording seam. It runs on every console route and, after a successful mutating
 * request, guarantees an audit event exists for it — so a new mutation route cannot silently
 * skip the trail even if the developer forgets to instrument its controller.
 *
 * The coordination with explicit instrumentation is a per-request tally: the middleware resets
 * the tally before the controller runs, and afterwards records a FALLBACK event only when the
 * tally is still zero. A controller that recorded a rich before/after event itself (a refund, a
 * wallet adjustment, a suspend) leaves the tally at one, so the middleware stays silent and the
 * event count is exactly one; an un-instrumented route gets the fallback, mapped to its action
 * via {@see AuditAction::forRoute()} (or the generic {@see AuditAction::ConsoleMutation}).
 *
 * A request is recorded only when it MUTATED: a write verb (POST/PUT/PATCH/DELETE), an
 * auditable route (not a read-only `*.preview`, the test-mode toggle, or auth), a non-error
 * response, and no flashed `error` (a guard that refused the action redirects back with one).
 */
class RecordsOperatorAudit
{
    /** Write verbs — a GET/HEAD read is never recorded. */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly RecordsAudit $recorder,
        private readonly AuditRequestTally $tally,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->tally->reset();

        $response = $next($request);

        if ($this->shouldRecord($request, $response)) {
            $this->recordFallback($request);
        }

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        if (! in_array($request->getMethod(), self::WRITE_METHODS, true)) {
            return false;
        }

        // An explicitly-instrumented controller already recorded a (richer) event this request.
        if ($this->tally->count() > 0) {
            return false;
        }

        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        if (! is_string($name) || ! AuditAction::isAuditable($name)) {
            return false;
        }

        // A failed action redirects back with a flashed `error` (or validation `errors`); it
        // did not mutate, so it is not recorded.
        if ($response->getStatusCode() >= 400 || $this->requestFailed($request)) {
            return false;
        }

        return true;
    }

    /** Whether the just-run request flashed an error (an action guard refused it). */
    private function requestFailed(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();

        return $session->has('error') || $session->has('errors');
    }

    private function recordFallback(Request $request): void
    {
        $route = $request->route();
        $name = $route instanceof Route ? (string) $route->getName() : '';

        $action = AuditAction::forRoute($name) ?? AuditAction::ConsoleMutation;
        $target = $this->targetFor($request);

        $summary = $action === AuditAction::ConsoleMutation
            ? sprintf('Console mutation via %s %s', $request->getMethod(), $request->path())
            : $action->label();

        $this->recorder->record($action, $target, $summary, [
            'route' => $name !== '' ? $name : $request->path(),
            'method' => $request->getMethod(),
        ]);
    }

    /**
     * Best-effort target from the route parameters: the first bound model (its type + key and,
     * where present, its organization) or the first scalar parameter as an opaque id.
     */
    private function targetFor(Request $request): AuditTarget
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return AuditTarget::none();
        }

        foreach ($route->parameters() as $key => $value) {
            if ($value instanceof Model) {
                return AuditTarget::model($value);
            }

            if (is_scalar($value)) {
                $org = $key === 'organization' ? (string) $value : null;

                return AuditTarget::of((string) $key, (string) $value, $org);
            }
        }

        return AuditTarget::none();
    }
}
