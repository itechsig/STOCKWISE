<?php

namespace Tests\Feature\Auth;

use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_guest_gets_401_on_protected_routes(): void
    {
        $this->getJson('/api/roles')->assertUnauthorized();
        $this->getJson('/api/permissions')->assertUnauthorized();
    }

    public function test_super_admin_can_list_roles_and_permissions(): void
    {
        $this->actingAsRole('super_admin');

        $this->getJson('/api/roles')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/permissions')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_karyawan_is_forbidden_from_roles_endpoint(): void
    {
        $this->actingAsRole('karyawan');

        $this->getJson('/api/roles')->assertForbidden();
    }

    public function test_admin_gudang_without_role_view_permission_is_forbidden(): void
    {
        // admin_gudang has no 'role.view' in the matrix
        $this->actingAsRole('admin_gudang');

        $this->getJson('/api/roles')->assertForbidden();
    }

    public function test_super_admin_bypasses_permission_checks(): void
    {
        $user = $this->actingAsRole('super_admin');

        $this->assertTrue($user->hasPermission('anything.not.in.catalog'));
        $this->assertTrue($user->hasPermission('opname.approve'));
    }

    public function test_permission_helper_reflects_role_matrix(): void
    {
        $purchasing = $this->actingAsRole('purchasing');

        $this->assertTrue($purchasing->hasPermission('po.create'));
        $this->assertFalse($purchasing->hasPermission('opname.approve'));
        $this->assertFalse($purchasing->hasPermission('item.safety_stock.update'));
    }
}
