<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * Billing declares its own roles + permissions; Cbox ID assigns them. These tests keep
 * the declared manifest (config/cbox-id-client.php) internally consistent so
 * `cbox-id:publish-manifest` never ships a role pointing at a permission that doesn't
 * exist. The live publish (against a Cbox ID instance holding the `apps.manifest` scope)
 * is a deploy step, not covered here.
 */
class CboxIdManifestTest extends TestCase
{
    /** @return array{permissions: list<array{key: string, description?: string}>, roles: list<array{key: string, name: string, description?: string, permissions: list<string>}>} */
    private function authz(): array
    {
        /** @var array{permissions: list<array{key: string, description?: string}>, roles: list<array{key: string, name: string, description?: string, permissions: list<string>}>} $authz */
        $authz = config('cbox-id-client.authz');

        return $authz;
    }

    public function test_the_publish_manifest_command_is_registered(): void
    {
        $this->assertArrayHasKey('cbox-id:publish-manifest', app('Illuminate\Contracts\Console\Kernel')->all());
    }

    public function test_every_permission_key_is_feature_action_shaped(): void
    {
        foreach ($this->authz()['permissions'] as $permission) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*$/',
                $permission['key'],
                "Permission '{$permission['key']}' is not a valid feature:action slug.",
            );
        }
    }

    public function test_every_role_only_grants_declared_permissions(): void
    {
        $declared = array_column($this->authz()['permissions'], 'key');

        $this->assertNotEmpty($declared);

        foreach ($this->authz()['roles'] as $role) {
            $this->assertNotSame('', $role['name']);
            foreach ($role['permissions'] as $granted) {
                $this->assertContains(
                    $granted,
                    $declared,
                    "Role '{$role['key']}' grants undeclared permission '{$granted}'.",
                );
            }
        }
    }

    public function test_the_three_billing_roles_are_declared_with_expected_scope(): void
    {
        $roles = [];
        foreach ($this->authz()['roles'] as $role) {
            $roles[$role['key']] = $role;
        }
        $allPermissions = array_column($this->authz()['permissions'], 'key');

        foreach (['billing-admin', 'billing-operator', 'billing-viewer'] as $expected) {
            $this->assertArrayHasKey($expected, $roles, "Missing role '{$expected}'.");
        }

        // Admin holds the full catalog.
        $this->assertEqualsCanonicalizing($allPermissions, $roles['billing-admin']['permissions']);

        // Viewer is read-only — every permission it grants ends in ':read'.
        foreach ($roles['billing-viewer']['permissions'] as $granted) {
            $this->assertStringEndsWith(':read', $granted);
        }

        // Operator sits between: more than the viewer, less than the admin.
        $this->assertGreaterThan(count($roles['billing-viewer']['permissions']), count($roles['billing-operator']['permissions']));
        $this->assertLessThan(count($roles['billing-admin']['permissions']), count($roles['billing-operator']['permissions']));
    }

    /**
     * Capabilities that gate the token-authed surface (per-org API-token scope) rather than a
     * console `billing.permission:` route — declared and role-granted, but intentionally never
     * appearing as route middleware. Every OTHER declared permission MUST gate a real route.
     *
     * @var list<string>
     */
    private const TOKEN_API_ALLOWLIST = ['usage:ingest', 'payments:read'];

    /**
     * The permission slugs every `billing.permission:<slug>` route middleware enforces,
     * gathered from the live router — the single source of truth for what the app enforces.
     *
     * @return list<string>
     */
    private function routePermissionSlugs(): array
    {
        $slugs = [];

        /** @var Router $router */
        $router = app('router');

        foreach ($router->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'billing.permission:')) {
                    $slugs[] = substr($middleware, strlen('billing.permission:'));
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    public function test_every_route_permission_slug_is_declared_in_the_manifest(): void
    {
        $declared = array_column($this->authz()['permissions'], 'key');
        $used = $this->routePermissionSlugs();

        // Guards the lockout class of bug (#3): a route enforcing an undeclared slug 403s
        // every principal once RBAC is enforced, since no role can carry a slug the manifest
        // does not declare — e.g. `approvals:decide` was enforced but never declared.
        $this->assertNotEmpty($used);

        foreach ($used as $slug) {
            $this->assertContains(
                $slug,
                $declared,
                "Route middleware enforces 'billing.permission:{$slug}' but the manifest ".
                'in config/cbox-id-client.php does not declare it — no role can grant it, so '.
                'every operator is locked out of that route when RBAC enforces.',
            );
        }
    }

    public function test_every_declared_permission_gates_a_route_or_is_an_allowlisted_token_capability(): void
    {
        $declared = array_column($this->authz()['permissions'], 'key');
        $used = $this->routePermissionSlugs();

        // The reverse drift guard: a declared permission that gates no route and is not a
        // documented token-API capability is dead vocabulary — flag it so the manifest and
        // the rbac-manifest doc's "every permission maps to a real screen" claim stay honest.
        foreach ($declared as $slug) {
            $this->assertTrue(
                in_array($slug, $used, true) || in_array($slug, self::TOKEN_API_ALLOWLIST, true),
                "Declared permission '{$slug}' gates no console route and is not in the ".
                'token-API allowlist — either wire it to a route, add it to TOKEN_API_ALLOWLIST '.
                'with a documented reason, or remove it.',
            );
        }
    }

    public function test_approvals_decide_is_declared_and_granted_to_the_approver_role(): void
    {
        $declared = array_column($this->authz()['permissions'], 'key');
        $this->assertContains('approvals:decide', $declared);

        $roles = [];
        foreach ($this->authz()['roles'] as $role) {
            $roles[$role['key']] = $role['permissions'];
        }

        // billing-admin is the approver: it must carry the slug the approval queue enforces.
        $this->assertContains('approvals:decide', $roles['billing-admin']);
    }
}
