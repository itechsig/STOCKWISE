<?php

namespace Tests\Unit;

use App\Support\Rbac\Rbac;
use PHPUnit\Framework\TestCase;

class RbacCatalogTest extends TestCase
{
    public function test_every_matrix_slug_exists_in_the_permission_catalog(): void
    {
        $known = Rbac::allPermissionSlugs();

        foreach (Rbac::MATRIX as $role => $slugs) {
            if ($slugs === ['*']) {
                continue;
            }
            foreach ($slugs as $slug) {
                $this->assertContains($slug, $known, "Role [{$role}] references unknown permission [{$slug}]");
            }
        }
    }

    public function test_permission_slugs_are_unique(): void
    {
        $slugs = Rbac::allPermissionSlugs();

        $this->assertSame(count($slugs), count(array_unique($slugs)));
    }

    public function test_super_admin_expands_to_all_permissions(): void
    {
        $this->assertEqualsCanonicalizing(
            Rbac::allPermissionSlugs(),
            Rbac::permissionsForRole('super_admin'),
        );
    }

    public function test_every_role_in_matrix_has_metadata(): void
    {
        foreach (array_keys(Rbac::MATRIX) as $role) {
            $this->assertArrayHasKey($role, Rbac::ROLES);
        }
    }
}
