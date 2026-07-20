<?php

declare(strict_types=1);

return [

    /*
     * The base URL (issuer) of the Cbox ID instance you authenticate against, e.g.
     * https://id.acme.com. The SDK discovers every endpoint (authorize, token,
     * userinfo, jwks, end-session) from `{issuer}/.well-known/openid-configuration`,
     * so this is usually the only endpoint you configure.
     */
    'issuer' => env('CBOX_ID_ISSUER'),

    /*
     * Your OAuth client credentials, registered on the Cbox ID instance. The secret
     * is required for confidential clients (server-side apps) and for machine tokens
     * and introspection.
     */
    'client_id' => env('CBOX_ID_CLIENT_ID'),
    'client_secret' => env('CBOX_ID_CLIENT_SECRET'),

    /*
     * Your app's callback URL — must exactly match one registered on the client.
     */
    'redirect' => env('CBOX_ID_REDIRECT_URI'),

    /*
     * The scopes requested at login. `openid` is required for an id_token.
     *
     * @var list<string>
     */
    'scopes' => ['openid', 'profile', 'email'],

    /*
     * The path of the hosted account / profile page on the Cbox ID instance that
     * `profileUrl()` / `redirectToProfile()` send a signed-in user to (self-service
     * password, MFA, passkeys, sessions). A `return_to` is appended so the page can
     * offer a link back to your app.
     */
    'account_path' => '/settings',

    /*
     * HTTP timeout (seconds) for back-channel calls, and how long the discovery
     * document and JWKS are cached.
     */
    'http_timeout' => (int) env('CBOX_ID_HTTP_TIMEOUT', 10),
    'cache_ttl' => (int) env('CBOX_ID_CACHE_TTL', 3600),

    /*
     * Inbound provisioning webhooks. Cbox ID pushes signed events (member added/removed,
     * role assigned/revoked, directory user provisioned, org suspended/reactivated) to
     * this app; the SDK verifies the `X-Cbox-Signature` HMAC against `secret` and hands
     * each verified event to the handlers this app registers in
     * {@see \App\Providers\CboxIdWebhookServiceProvider}.
     *
     * Deny-by-default: with no `secret`, the SDK receiver fails closed and refuses every
     * payload (it never trusts an unverified body). The receiver is mounted MANUALLY in
     * routes/webhooks.php at `/webhooks/cbox-id` — registered ahead of the payment-gateway
     * `/webhooks/{gateway}` route so the two never collide — hence `route => false` here
     * turns OFF the SDK's own auto-mount.
     */
    'webhooks' => [
        'secret' => env('CBOX_ID_WEBHOOK_SECRET'),

        // The SDK auto-mount is disabled — this app mounts the receiver itself (see
        // routes/webhooks.php) so it sits under `/webhooks/*` beside the gateway route,
        // rate-limited and deterministically ordered ahead of the `{gateway}` wildcard.
        'route' => false,
        'path' => env('CBOX_ID_WEBHOOK_PATH', '/webhooks/cbox-id'),

        // Reject a signature whose timestamp is outside this window (replay + skew bound).
        'tolerance' => (int) env('CBOX_ID_WEBHOOK_TOLERANCE', 300),

        // The SDK verifies + acknowledges immediately and runs the handlers on a queued
        // job (ProcessCboxIdWebhook); point these at a real async connection/queue in
        // production so a slow handler never stalls the ack. null uses the app defaults.
        'connection' => env('CBOX_ID_WEBHOOK_QUEUE_CONNECTION'),
        'queue' => env('CBOX_ID_WEBHOOK_QUEUE'),
    ],

    /*
     * The home environment key stamped on a billing org when a login carries no
     * `environment` claim (single-environment deployments). Cbox ID is environment-scoped
     * — each org lives inside exactly one environment — but the claim only appears once a
     * coordinated Cbox ID release emits it; until then every org groups under this default
     * so a host-less single-tenant deploy Just Works. See docs/identity/tenancy.md.
     */
    'environment_default' => env('CBOX_ID_ENVIRONMENT_DEFAULT', 'default'),

    /*
     * Authorization manifest — declare this app's ROLES and PERMISSIONS in code, and
     * `php artisan cbox-id:publish-manifest` (e.g. on deploy) pushes them to Cbox ID.
     * Cbox ID owns identity + assignment; your app owns what a role means. Assigned
     * roles then arrive in the token's `roles`/`permissions` claims for you to enforce.
     * Requires the app's client to hold the `apps.manifest` scope.
     *
     * Permissions are `feature:action` keys; each role grants a subset of them.
     */
    'authz' => [
        // Billing's real capability catalog — every `feature:action` maps to an actual
        // console screen / management-API operation. Cbox ID assigns the roles below to
        // users; this app enforces the permissions they carry.
        'permissions' => [
            ['key' => 'invoices:read', 'description' => 'View invoices and credit notes'],
            ['key' => 'invoices:manage', 'description' => 'Create, void, mark-paid and resend invoices'],
            ['key' => 'invoices:refund', 'description' => 'Issue refunds and credit notes'],

            ['key' => 'subscriptions:read', 'description' => 'View subscriptions'],
            ['key' => 'subscriptions:manage', 'description' => 'Create, change, pause, cancel and reactivate subscriptions'],

            ['key' => 'quotes:read', 'description' => 'View sales quotes, contracts and the approval queue'],
            ['key' => 'quotes:manage', 'description' => 'Author, send, expire and clone sales quotes'],
            ['key' => 'quotes:approve', 'description' => 'Approve or reject quotes above the deal-desk threshold'],

            // The general maker-checker engine: decide (approve/reject) sensitive money actions
            // held for a SECOND operator. Gates the whole approval queue + each decision route.
            ['key' => 'approvals:decide', 'description' => 'Approve or reject held maker-checker requests (two-person rule)'],

            ['key' => 'usage:read', 'description' => 'View metered usage'],
            // Hot-path capability: the token-authed /api/v1 reserve/commit/record surface is gated
            // by per-org API-token scope, not the console `billing.permission:` middleware — so this
            // slug is a real granted capability that intentionally never appears as route middleware.
            ['key' => 'usage:ingest', 'description' => 'Reserve, commit and record usage on the enforcement hot path'],

            ['key' => 'catalog:read', 'description' => 'View products, plans, prices and meters'],
            ['key' => 'catalog:manage', 'description' => 'Create and edit products, plans, prices and meters'],

            ['key' => 'customers:read', 'description' => 'View billing organizations and their entitlements'],
            ['key' => 'customers:manage', 'description' => 'Provision, edit, suspend and reactivate billing organizations'],

            ['key' => 'wallet:manage', 'description' => 'Grant and debit organization wallet credit'],

            // The read counterpart to payments:manage — assigned to every role for entitlement /
            // gateway-state reads; the console mutating routes carry payments:manage, so this
            // read slug rounds out the read/manage matrix rather than gating a console screen.
            ['key' => 'payments:read', 'description' => 'View payment methods and gateway state'],
            ['key' => 'payments:manage', 'description' => 'Manage payment methods, checkout, portal and intents'],

            ['key' => 'licenses:read', 'description' => 'View issued on-prem licenses'],
            ['key' => 'licenses:issue', 'description' => 'Issue and renew on-prem licenses'],
            ['key' => 'licenses:revoke', 'description' => 'Revoke on-prem licenses'],

            ['key' => 'analytics:read', 'description' => 'View revenue, retention and usage analytics'],

            ['key' => 'settings:read', 'description' => 'View seller entities, tax, gateways, API tokens and webhooks'],
            ['key' => 'settings:manage', 'description' => 'Configure seller entities, tax, gateways, API tokens and webhooks'],
        ],
        'roles' => [
            [
                'key' => 'billing-admin',
                'name' => 'Billing Admin',
                'description' => 'Full access to every billing capability, including catalog and settings configuration.',
                'permissions' => [
                    'invoices:read', 'invoices:manage', 'invoices:refund',
                    'subscriptions:read', 'subscriptions:manage',
                    'quotes:read', 'quotes:manage', 'quotes:approve',
                    'approvals:decide',
                    'usage:read', 'usage:ingest',
                    'catalog:read', 'catalog:manage',
                    'customers:read', 'customers:manage',
                    'wallet:manage',
                    'payments:read', 'payments:manage',
                    'licenses:read', 'licenses:issue', 'licenses:revoke',
                    'analytics:read',
                    'settings:read', 'settings:manage',
                ],
            ],
            [
                'key' => 'billing-operator',
                'name' => 'Billing Operator',
                'description' => 'Day-to-day billing operations — manage subscriptions, refunds, customers, payments and licenses — without catalog or platform-settings changes.',
                'permissions' => [
                    'invoices:read', 'invoices:manage', 'invoices:refund',
                    'subscriptions:read', 'subscriptions:manage',
                    'quotes:read', 'quotes:manage',
                    'usage:read', 'usage:ingest',
                    'catalog:read',
                    'customers:read', 'customers:manage',
                    'wallet:manage',
                    'payments:read', 'payments:manage',
                    'licenses:read', 'licenses:issue', 'licenses:revoke',
                    'analytics:read',
                    'settings:read',
                ],
            ],
            [
                'key' => 'billing-viewer',
                'name' => 'Billing Viewer',
                'description' => 'Read-only access to billing data and analytics.',
                'permissions' => [
                    'invoices:read',
                    'subscriptions:read',
                    'quotes:read',
                    'usage:read',
                    'catalog:read',
                    'customers:read',
                    'payments:read',
                    'licenses:read',
                    'analytics:read',
                    'settings:read',
                ],
            ],
        ],
    ],

];
