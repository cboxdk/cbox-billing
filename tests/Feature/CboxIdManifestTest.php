<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
