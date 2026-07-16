<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\AuthedUser;
use App\Auth\Contracts\IdentityProvider;
use App\Auth\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * OIDC relying-party endpoints for signing in against Cbox ID. Thin: it drives the
 * flow and maps results to redirects/session; the protocol lives in {@see IdentityProvider}.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $idp,
        private readonly CurrentUser $current,
    ) {}

    /** The sign-in screen. */
    public function login(): View|RedirectResponse
    {
        if ($this->current->check()) {
            return redirect()->route('billing.dashboard');
        }

        return view('auth.login', [
            'configured' => $this->idp->isConfigured(),
            'demoAllowed' => $this->demoAllowed(),
        ]);
    }

    /** Kick off the authorization-code + PKCE redirect to Cbox ID. */
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($this->idp->isConfigured(), 404);

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put('oidc.state', $state);
        $request->session()->put('oidc.nonce', $nonce);
        $request->session()->put('oidc.verifier', $verifier);

        return redirect()->away($this->idp->authorizationUrl($state, $nonce, $challenge));
    }

    /** Handle the redirect back from Cbox ID: verify state, exchange, validate id_token. */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('login')->with('error', 'Sign-in was cancelled or denied by Cbox ID.');
        }

        $session = $request->session();
        $expectedState = $session->pull('oidc.state');
        $nonce = $session->pull('oidc.nonce');
        $verifier = $session->pull('oidc.verifier');

        if (! is_string($expectedState) || $expectedState === '' || ! hash_equals($expectedState, $this->queryString($request, 'state'))) {
            return redirect()->route('login')->with('error', 'Sign-in failed: state mismatch. Please try again.');
        }

        try {
            $tokens = $this->idp->exchangeCode($this->queryString($request, 'code'), is_string($verifier) ? $verifier : '');
            $claims = $this->idp->verifyIdToken($tokens['id_token'], is_string($nonce) ? $nonce : '');

            // The id_token carries identity + auth facts (sub, org, amr); profile
            // fields (name, email) come from UserInfo. Merge them, but only when the
            // UserInfo subject matches the id_token subject.
            if (! empty($tokens['access_token'])) {
                $userInfo = $this->idp->fetchUserInfo((string) $tokens['access_token']);
                if (($userInfo['sub'] ?? null) === ($claims['sub'] ?? null)) {
                    $claims = array_merge($claims, array_filter($userInfo, static fn ($v) => $v !== null && $v !== ''));
                }
            }
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->with('error', 'Sign-in failed: could not verify your identity.');
        }

        $session->regenerate();
        $this->current->login(AuthedUser::fromClaims($claims), $tokens['id_token']);

        return redirect()->intended(route('billing.dashboard'));
    }

    /** Local/demo sign-in when no live Cbox ID is configured. */
    public function demo(Request $request): RedirectResponse
    {
        abort_unless($this->demoAllowed(), 404);

        $request->session()->regenerate();
        $this->current->login(new AuthedUser(
            sub: 'demo|sylvester',
            name: 'Sylvester Damgaard',
            email: 'sn@cbox.dk',
            org: '01demo0org0systems',
            picture: null,
            orgName: 'Cbox Systems',
        ));

        return redirect()->intended(route('billing.dashboard'));
    }

    /** Sign out locally and, where advertised, via RP-initiated logout at Cbox ID. */
    public function logout(Request $request): RedirectResponse
    {
        $idToken = $this->current->idToken();
        $this->current->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($this->idp->isConfigured()) {
            $endSession = $this->idp->endSessionUrl($idToken, route('login'));
            if ($endSession !== null) {
                return redirect()->away($endSession);
            }
        }

        return redirect()->route('login');
    }

    /** Demo sign-in is offered only when there is no live provider to authenticate against. */
    private function demoAllowed(): bool
    {
        return ! $this->idp->isConfigured();
    }

    /** A single query parameter as a string; array/absent values collapse to empty. */
    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }
}
