---
title: OIDC login
description: The authorization-code + PKCE sign-in flow against Cbox ID — endpoint discovery, id_token verification, demo mode when no provider is configured, and RP-initiated logout.
weight: 31
---

# OIDC login

Cbox Billing signs operators in against a Cbox ID instance using the OIDC
authorization-code flow with PKCE. The protocol lives in the `IdentityProvider`
(`CboxIdOidc`, from `cboxdk/laravel-id-client`); the `AuthController` is a thin
driver over it.

## Configuration

```dotenv
CBOX_ID_ISSUER=https://id.acme.com
CBOX_ID_CLIENT_ID=...
CBOX_ID_CLIENT_SECRET=...
CBOX_ID_REDIRECT_URI=https://billing.acme.com/auth/callback
CBOX_ID_SCOPES="openid profile email"
```

The SDK **discovers every endpoint** (authorize, token, userinfo, jwks,
end-session) from `{issuer}/.well-known/openid-configuration`, so the issuer is
usually the only endpoint you configure. The discovery document and JWKS are cached
for `CBOX_ID_CACHE_TTL` seconds (default 3600).

## The flow

The routes live in `routes/web.php`:

| Route | Purpose |
| --- | --- |
| `GET /login` | The sign-in screen. Redirects to the dashboard if already authenticated. |
| `GET /auth/redirect` | Starts the authorization-code + PKCE redirect to Cbox ID. |
| `GET /auth/callback` | Handles the redirect back: verifies state, exchanges the code, validates the `id_token`. |
| `POST /auth/demo` | Local/demo sign-in (only when no provider is configured). |
| `POST /logout` | Sign out locally and, where advertised, via RP-initiated logout. |

Step by step:

1. **Redirect** — the app generates `state`, `nonce`, and a PKCE `verifier` +
   `challenge`, stashes them in the session, and redirects to the authorize URL.
   If no provider is configured, `/auth/redirect` 404s.
2. **Callback** — the app verifies the `state` matches (constant-time), exchanges
   the code for tokens using the PKCE verifier, and validates the `id_token`
   against the expected `nonce` (signature verified via `firebase/php-jwt` against
   the discovered JWKS).
3. **Claims merge** — identity/auth facts (`sub`, `org`, `amr`) come from the
   `id_token`; profile fields (`name`, `email`, `picture`) come from UserInfo, and
   are merged **only when the UserInfo subject matches the id_token subject**.
4. **Session** — the session is regenerated and the authenticated user is
   established.

Any verification failure returns to `/login` with a generic error — the app never
leaks why identity verification failed.

## Demo mode

When `CBOX_ID_ISSUER` is **empty**, there is no live provider to authenticate
against, so the login screen offers a **demo sign-in** button. It establishes a
local operator session (a fixed demo identity and organization) and lands on the
dashboard. Demo sign-in is offered **only** while no provider is configured — it
disappears the moment you set the issuer. This is the zero-config path used in the
[quick start](../quickstart.md).

## Logout

`POST /logout` clears the local session (invalidate + token regenerate). If the
provider advertises an end-session endpoint, the app then performs RP-initiated
logout, sending the user to Cbox ID's `end_session` with a post-logout redirect
back to `/login`.

## The operator model

The provider console is a **single operator surface**: there is no local roles
table, so any authenticated Cbox ID session administers it. Finer-grained access is
expressed through the [federated RBAC manifest](rbac-manifest.md) — the roles and
permissions the app declares and Cbox ID assigns.

## Related documentation

- [Federated RBAC manifest](rbac-manifest.md)
- [Org-level entitlements](entitlements.md)
- Identity client: <https://github.com/cboxdk/laravel-id>
