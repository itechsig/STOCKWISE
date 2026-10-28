<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_login_with_valid_credentials_returns_token_and_user(): void
    {
        $user = User::factory()->create([
            'username' => 'wahyu',
            'password' => Hash::make('secret123'),
        ]);
        $user->roles()->attach(Role::where('slug', 'admin_gudang')->first());

        $response = $this->postJson('/api/login', [
            'username' => 'wahyu',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'username', 'roles', 'permissions']]);

        $this->assertContains('admin_gudang', $response->json('user.roles'));
        $this->assertContains('opname.approve', $response->json('user.permissions'));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        User::factory()->create(['username' => 'wahyu', 'password' => Hash::make('secret123')]);

        $this->postJson('/api/login', ['username' => 'wahyu', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_login_unknown_username_is_rejected(): void
    {
        $this->postJson('/api/login', ['username' => 'ghost', 'password' => 'whatever'])
            ->assertStatus(422);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create([
            'username' => 'mantan',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/login', ['username' => 'mantan', 'password' => 'secret123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_login_can_use_email(): void
    {
        User::factory()->create([
            'username' => 'agus',
            'email' => 'agus@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/login', ['username' => 'agus@example.com', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('user.username', 'agus');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/login', ['username' => 'x', 'password' => 'y']);
        }

        $this->postJson('/api/login', ['username' => 'x', 'password' => 'y'])
            ->assertStatus(429);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_returns_current_user_with_permissions(): void
    {
        $user = $this->actingAsRole('purchasing');

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.roles.0', 'purchasing')
            ->assertJsonPath('user.permissions', fn ($p) => in_array('po.create', $p, true));
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('spa')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Fresh guard resolution (a real 2nd HTTP request would be a new process).
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }
}
