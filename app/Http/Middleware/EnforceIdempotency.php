<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in request idempotency for the mutating management endpoints. A client that sends an
 * `Idempotency-Key` header gets exactly-once semantics: the FIRST attempt runs and its
 * response is stored; a retry with the same key replays that stored response instead of
 * re-running the effect, so a network retry of `POST /subscriptions` (or a plan change /
 * quantity / add-on / license issue) can never create a duplicate.
 *
 * Deny-by-default toward misuse:
 *  - the record is scoped by a hash of the caller's bearer token, so keys never collide
 *    across tenants;
 *  - reusing a key with a DIFFERENT payload is a `409` conflict, never a silent replay;
 *  - a concurrent duplicate that arrives while the first is still in flight gets a `409`
 *    rather than a second effect (the unique constraint is the lock);
 *  - only a definitive success (2xx) is stored — a failed first attempt releases the key so
 *    a genuine retry can proceed.
 *
 * A request without the header is passed straight through (idempotency is opt-in).
 */
class EnforceIdempotency
{
    private const string HEADER = 'Idempotency-Key';

    /**
     * A response may declare secret JSON fields via this header (comma-separated). Those
     * fields are stripped from the copy PERSISTED for replay (SEC-2), so a show-once artifact
     * such as a signed license `key` never lands at rest in `idempotency_keys`. The original
     * caller still receives the full body on its first 2xx; a later replay gets the redacted
     * copy — the field present but null, a clear signal the secret is not replayable.
     */
    private const string REDACT_HEADER = 'X-Idempotency-Redact';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->headers->get(self::HEADER, ''));

        if ($key === '') {
            return $next($request);
        }

        $scope = $this->scope($request);
        $requestHash = $this->requestHash($request);

        // A record already exists for this (key, caller): replay, conflict, or in-flight.
        if (($existing = $this->find($key, $scope)) !== null) {
            return $this->resolveExisting($existing, $requestHash);
        }

        // Claim the key. The unique (idempotency_key, scope) constraint makes this the lock:
        // a racing duplicate loses the insert and falls back to the existing record.
        try {
            $record = IdempotencyKey::query()->create([
                'idempotency_key' => $key,
                'scope' => $scope,
                'method' => $request->getMethod(),
                'path' => $request->path(),
                'request_hash' => $requestHash,
            ]);
        } catch (QueryException) {
            // Lost the insert race to a concurrent duplicate — fall back to its record.
            $claimed = IdempotencyKey::query()
                ->where('idempotency_key', $key)
                ->where('scope', $scope)
                ->first();

            return $claimed instanceof IdempotencyKey
                ? $this->resolveExisting($claimed, $requestHash)
                : $this->conflict('A request with this Idempotency-Key is already in progress.');
        }

        $response = $next($request);

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $record->forceFill([
                'response_status' => $status,
                'response_body' => $this->bodyForStorage($response),
            ])->save();
        } else {
            // No effect committed — free the key so the caller can retry it cleanly.
            $record->delete();
        }

        // The redaction directive is an internal contract between the controller and this
        // middleware — never leak it to the client.
        $response->headers->remove(self::REDACT_HEADER);
        $response->headers->set(self::HEADER, $key);

        return $response;
    }

    /**
     * The response body as it is PERSISTED for replay: the raw content, minus any secret
     * fields the response declared via {@see REDACT_HEADER} (SEC-2). Redaction nulls the named
     * top-level JSON fields, so the stored row carries the response shape without the secret.
     */
    private function bodyForStorage(Response $response): ?string
    {
        $content = $response->getContent();

        if ($content === false || $content === '') {
            return $content === false ? null : $content;
        }

        $fields = $this->redactedFields($response);

        if ($fields === []) {
            return $content;
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            // Not a JSON object we can redact field-wise — do not risk persisting the secret.
            return null;
        }

        foreach ($fields as $field) {
            if (array_key_exists($field, $decoded)) {
                $decoded[$field] = null;
            }
        }

        $encoded = json_encode($decoded);

        return $encoded === false ? null : $encoded;
    }

    /**
     * The secret field names a response asked to have redacted from the stored replay copy.
     *
     * @return list<string>
     */
    private function redactedFields(Response $response): array
    {
        $header = $response->headers->get(self::REDACT_HEADER);

        if (! is_string($header) || trim($header) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $header)), static fn (string $f): bool => $f !== ''));
    }

    /** Replay, conflict, or in-flight, given a matching record and the retry's fingerprint. */
    private function resolveExisting(IdempotencyKey $existing, string $requestHash): Response
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            return $this->conflict('This Idempotency-Key was already used with a different request payload.');
        }

        if (! $existing->isComplete()) {
            return $this->conflict('A request with this Idempotency-Key is already in progress.');
        }

        return $this->replay($existing);
    }

    private function find(string $key, string $scope): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('idempotency_key', $key)
            ->where('scope', $scope)
            ->first();
    }

    /** Re-emit the first attempt's stored response, flagged as a replay. */
    private function replay(IdempotencyKey $record): Response
    {
        $response = new Response(
            $record->response_body ?? '',
            $record->response_status ?? Response::HTTP_OK,
        );

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set(self::HEADER, $record->idempotency_key);
        $response->headers->set('Idempotency-Replayed', 'true');

        return $response;
    }

    private function conflict(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_CONFLICT);
    }

    /** The caller scope: a hash of the bearer token, so keys never collide across tenants. */
    private function scope(Request $request): string
    {
        $bearer = $request->bearerToken();

        return $bearer !== null && $bearer !== '' ? hash('sha256', $bearer) : 'anon';
    }

    /** A fingerprint of the request so a key reused with a different payload is a conflict. */
    private function requestHash(Request $request): string
    {
        return hash('sha256', $request->getMethod().'|'.$request->path().'|'.$request->getContent());
    }
}
