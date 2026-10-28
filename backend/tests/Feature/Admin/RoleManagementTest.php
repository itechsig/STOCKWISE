<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = RbacSeeder::class;

    private function superAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->roles()->attach(Role::where('slug', 'super_admin')->firstOrFail());

        return $u;
    }

    public function test_requires_role_manage(): void
    {
        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/roles', ['name' => 'X', 'permissions' => []])->assertForbidden();
    }

    public function test_create_update_delete_custom_role_and_its_permissions_take_effect(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $role = $this->postJson('/api/roles', [
            'name' => 'Viewer Sync', 'description' => 'hanya lihat sync', 'permissions' => ['sync.accurate.view'],
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'viewer_sync')
            ->assertJsonPath('data.is_system', false)
            ->json('data');

        // the new role actually grants access
        $u = User::factory()->create(['is_active' => true]);
        $u->roles()->attach($role['id']);
        Sanctum::actingAs($u);
        $this->getJson('/api/sync/history')->assertOk();
        $this->getJson('/api/users')->assertForbidden();

        Sanctum::actingAs($this->superAdmin());
        $this->putJson("/api/roles/{$role['id']}", ['name' => 'Viewer Sync 2', 'permissions' => ['sync.accurate.view', 'user.view']])
            ->assertOk()->assertJsonPath('data.name', 'Viewer Sync 2')
            ->assertJsonCount(2, 'data.permissions');

        // in use -> cannot delete
        $this->deleteJson("/api/roles/{$role['id']}")->assertStatus(409);
        $u->roles()->detach();
        $this->deleteJson("/api/roles/{$role['id']}")->assertOk();
        $this->assertNull(Role::find($role['id']));
    }

    public function test_super_admin_role_is_locked_and_system_roles_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $super = Role::where('slug', 'super_admin')->first();
        $gudang = Role::where('slug', 'admin_gudang')->first();

        $this->putJson("/api/roles/{$super->id}", ['permissions' => []])->assertStatus(422);
        $this->deleteJson("/api/roles/{$super->id}")->assertStatus(422);
        $this->deleteJson("/api/roles/{$gudang->id}")->assertStatus(422);

        // system roles can still be renamed / re-permissioned
        $this->putJson("/api/roles/{$gudang->id}", ['description' => 'baru'])->assertOk();
    }

    public function test_validation(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/roles', ['name' => 'Admin Gudang', 'permissions' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/roles', ['name' => 'Baru', 'permissions' => ['bukan.permission']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
        $this->postJson('/api/roles', ['name' => 'Baru2', 'slug' => 'Bad Slug!', 'permissions' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }
}
