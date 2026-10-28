<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = RbacSeeder::class;

    private function makeUser(string $role, array $attrs = []): User
    {
        $u = User::factory()->create($attrs + ['is_active' => true]);
        $u->roles()->attach(Role::where('slug', $role)->firstOrFail());

        return $u;
    }

    /** A role that can manage users but is NOT super_admin. */
    private function userAdmin(): User
    {
        $role = Role::create(['slug' => 'user_admin', 'name' => 'User Admin', 'is_system' => false]);
        $role->permissions()->sync(\App\Models\Permission::whereIn('slug', [
            'user.view', 'user.create', 'user.update', 'user.delete', 'user.assign_role',
        ])->pluck('id'));
        $u = User::factory()->create(['is_active' => true]);
        $u->roles()->attach($role);

        return $u;
    }

    private function validNew(array $over = []): array
    {
        return $over + [
            'name' => 'Budi Gudang',
            'username' => 'budi',
            'email' => 'budi@example.test',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
            'roles' => ['karyawan'],
        ];
    }

    public function test_permissions_are_required(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
        Sanctum::actingAs($this->makeUser('karyawan'));
        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/users', $this->validNew())->assertForbidden();
    }

    public function test_super_admin_can_crud_a_user(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $id = $this->postJson('/api/users', $this->validNew())->assertCreated()
            ->assertJsonPath('data.username', 'budi')
            ->assertJsonPath('data.roles.0.slug', 'karyawan')
            ->json('data.id');
        $this->assertTrue(Hash::check('Rahasia123', User::find($id)->password));

        $this->getJson('/api/users?search=budi')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/users/{$id}")->assertOk()->assertJsonMissingPath('data.password');

        $this->putJson("/api/users/{$id}", ['name' => 'Budi S', 'roles' => ['admin_gudang']])->assertOk()
            ->assertJsonPath('data.name', 'Budi S')
            ->assertJsonPath('data.roles.0.slug', 'admin_gudang');

        $this->deleteJson("/api/users/{$id}")->assertOk();
        $this->assertNull(User::find($id));
    }

    public function test_validation_rejects_duplicates_weak_and_default_passwords(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));
        $this->makeUser('karyawan', ['username' => 'budi']);

        $this->postJson('/api/users', $this->validNew())->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson('/api/users', $this->validNew(['username' => 'x1', 'password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/users', $this->validNew([
            'username' => 'x2', 'password' => UserSeeder::PASSWORD, 'password_confirmation' => UserSeeder::PASSWORD,
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/users', $this->validNew(['username' => 'x3', 'roles' => ['tidak_ada']]))
            ->assertUnprocessable()->assertJsonValidationErrors('roles.0');
    }

    public function test_non_super_admin_cannot_escalate_to_super_admin(): void
    {
        $actor = $this->userAdmin();
        Sanctum::actingAs($actor);

        $this->postJson('/api/users', $this->validNew(['roles' => ['super_admin']]))->assertForbidden();
        $this->putJson("/api/users/{$actor->id}", ['roles' => ['user_admin', 'super_admin']])->assertForbidden();

        $boss = $this->makeUser('super_admin');
        $this->putJson("/api/users/{$boss->id}", ['name' => 'hacked'])->assertForbidden();
        $this->postJson("/api/users/{$boss->id}/password", ['password' => 'Baru12345', 'password_confirmation' => 'Baru12345'])->assertForbidden();
        $this->deleteJson("/api/users/{$boss->id}")->assertForbidden();

        // but ordinary users are fine
        $this->postJson('/api/users', $this->validNew())->assertCreated();
    }

    public function test_role_change_needs_assign_role_permission(): void
    {
        $role = Role::create(['slug' => 'editor_only', 'name' => 'Editor', 'is_system' => false]);
        $role->permissions()->sync(\App\Models\Permission::whereIn('slug', ['user.view', 'user.update'])->pluck('id'));
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach($role);
        $target = $this->makeUser('karyawan');
        Sanctum::actingAs($actor);

        $this->putJson("/api/users/{$target->id}", ['name' => 'OK'])->assertOk();
        $this->putJson("/api/users/{$target->id}", ['roles' => ['admin_gudang']])->assertForbidden();
    }

    public function test_cannot_deactivate_or_delete_self_or_last_super_admin(): void
    {
        $me = $this->makeUser('super_admin');
        Sanctum::actingAs($me);

        $this->putJson("/api/users/{$me->id}", ['is_active' => false])->assertStatus(422);
        $this->deleteJson("/api/users/{$me->id}")->assertStatus(422);
        $this->putJson("/api/users/{$me->id}", ['roles' => ['karyawan']])->assertStatus(422);

        // with a second super admin, demoting/deactivating the OTHER one works
        $other = $this->makeUser('super_admin');
        $this->putJson("/api/users/{$other->id}", ['is_active' => false])->assertOk();
    }

    public function test_deactivation_and_password_reset_revoke_tokens(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));
        $target = $this->makeUser('karyawan');
        $target->createToken('a', ['app']);
        $target->createToken('b', ['app']);

        $this->postJson("/api/users/{$target->id}/password", ['password' => 'Baru12345', 'password_confirmation' => 'Baru12345'])->assertOk();
        $this->assertSame(0, $target->tokens()->count());
        $this->assertTrue(Hash::check('Baru12345', $target->fresh()->password));

        $target->createToken('c', ['app']);
        $this->putJson("/api/users/{$target->id}", ['is_active' => false])->assertOk();
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_user_with_history_cannot_be_hard_deleted(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));
        $target = $this->makeUser('karyawan');

        // find any table with a created_by FK to users and give the target a row there
        $table = collect(Schema::getTableListing(schemaQualified: false))->first(fn ($t) => collect(Schema::getForeignKeys($t))
            ->contains(fn ($fk) => $fk['foreign_table'] === 'users' && $fk['columns'] === ['created_by']));
        $this->assertNotNull($table, 'expected at least one table with created_by -> users');
        $this->assertTrue(User::find($target->id) !== null);

        $row = collect(Schema::getColumns($table))
            ->reject(fn ($c) => $c['nullable'] || $c['auto_increment'] || $c['default'] !== null)
            ->mapWithKeys(fn ($c) => [$c['name'] => str_contains($c['type_name'], 'int') ? 1 : 'x'])
            ->all();
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table($table)->insert($row + ['created_by' => $target->id]);
        DB::statement('PRAGMA foreign_keys = ON');

        $this->deleteJson("/api/users/{$target->id}")->assertStatus(409);
        $this->assertNotNull(User::find($target->id));
    }

    public function test_options_lists_roles_and_sites(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));
        $this->getJson('/api/users/options')->assertOk()
            ->assertJsonStructure(['data' => ['roles' => [['id', 'slug', 'name', 'is_system']], 'sites']]);
    }

    public function test_self_service_password_change(): void
    {
        $me = $this->makeUser('karyawan', ['username' => 'saya']);
        $token = $this->postJson('/api/login', ['username' => 'saya', 'password' => 'password'])->assertOk()->json('token');
        $me->createToken('other-device', ['app']);

        $this->withToken($token)->postJson('/api/me/password', [
            'current_password' => 'salah', 'password' => 'BaruSekali1', 'password_confirmation' => 'BaruSekali1',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->withToken($token)->postJson('/api/me/password', [
            'current_password' => 'password', 'password' => 'BaruSekali1', 'password_confirmation' => 'BaruSekali1',
        ])->assertOk();

        $this->assertTrue(Hash::check('BaruSekali1', $me->fresh()->password));
        $this->assertSame(1, $me->tokens()->count(), 'only the current session survives');
        $this->withToken($token)->getJson('/api/me')->assertOk();
    }
}
