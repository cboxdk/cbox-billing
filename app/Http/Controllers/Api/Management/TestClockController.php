<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Mode\BillingContext;
use App\Billing\TestMode\TestClockAdvancer;
use App\Http\Controllers\Api\ApiController;
use App\Models\TestClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The programmatic test-clock advance: `POST /api/v1/test/clocks/{id}/advance`. Restricted to
 * a TEST-mode token (deny-by-default — a live token is refused), it fast-forwards the clock's
 * virtual time and runs the due billing logic for the bound test subscriptions, returning the
 * counts of what fired. This is how an integrator scripts "simulate a year of renewals" from a
 * test suite or a CI job.
 *
 * Thin over the {@see TestClockAdvancer}; the whole advance runs in the test plane.
 */
class TestClockController extends ApiController
{
    public function __construct(private readonly BillingContext $context) {}

    public function advance(Request $request, string $id, TestClockAdvancer $advancer): JsonResponse
    {
        // Deny-by-default: only a test-mode credential may drive a test clock.
        if (! $this->context->isTest()) {
            return new JsonResponse(['error' => 'A test-mode API token is required to advance a test clock.'], 403);
        }

        $request->validate([
            'target' => ['required', 'date'],
        ]);

        $clock = TestClock::query()->find($id);

        if (! $clock instanceof TestClock) {
            return new JsonResponse(['error' => 'Test clock not found.'], 404);
        }

        $result = $advancer->advance($clock, CarbonImmutable::parse($request->string('target')->toString()));

        return new JsonResponse([
            'clock' => ['id' => $clock->id, 'name' => $clock->name, 'now_at' => $result->to->toIso8601String()],
            'advanced_from' => $result->from->toIso8601String(),
            'renewals' => $result->renewals,
            'trial_conversions' => $result->trialConversions,
            'dunning_attempts' => $result->dunningAttempts,
            'invoices' => $result->invoices,
        ]);
    }
}
