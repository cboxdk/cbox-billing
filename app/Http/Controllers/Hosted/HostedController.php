<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Hosted\Enums\SessionStatus;
use App\Billing\Hosted\Enums\SessionType;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Http\Controllers\Controller;
use App\Models\BillingSession;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared plumbing for the token-authorized hosted pages. The opaque session token in the
 * URL is the whole authorization — there is no provider `auth.cbox` session here — so an
 * absent, wrong-type, or expired token resolves to a 404 rather than leaking that a token
 * ever existed.
 */
abstract class HostedController extends Controller
{
    public function __construct(
        protected readonly ManagesBillingSessions $sessions,
        protected readonly BillingContext $context,
    ) {}

    /**
     * Resolve a session usable enough to render/act on: it must exist, match the expected
     * type, and not be expired. A 404 (not a redirect) is the deny-by-default answer for
     * anything else.
     *
     * The session is the SOURCE OF TRUTH for the request's plane (HP1): the public hosted
     * routes carry no credential to set the mode, so once the token resolves we set the
     * ambient {@see BillingContext} from the session's `livemode` BEFORE any org / plan /
     * subscription / gateway query runs — a test session then resolves and acts on ONLY its
     * test-plane data, and vice-versa.
     *
     * @throws NotFoundHttpException
     */
    protected function require(string $token, SessionType $type): BillingSession
    {
        $session = $this->sessions->locate($token, $type);

        if (! $session instanceof BillingSession || $session->status === SessionStatus::Expired) {
            throw new NotFoundHttpException('This session is no longer available.');
        }

        $this->context->setMode(BillingMode::fromLivemode($session->livemode));

        return $session;
    }
}
