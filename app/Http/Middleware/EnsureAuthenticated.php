<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\CurrentUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the app: a request without an authenticated Cbox ID session is bounced to
 * the sign-in screen, remembering where it was headed. Deny-by-default.
 */
class EnsureAuthenticated
{
    public function __construct(private readonly CurrentUser $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->current->check()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('login');
        }

        return $next($request);
    }
}
