<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Uses REAL tokens (POST /api/login, and a manually created token) instead of
 * Sanctum::actingAs(), because actingAs() without abilities hides the actual
 * risk: a token created without explicit abilities gets ['*'], and '*'
 * satisfies `abilities:agent:sync`.
 */
class TokenScopeTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = RbacSeeder::class;

    private function loginToken(string $role = 'karyawan'): string
    {
        $user = User::factory()->create(['username' => 'u_'.$role, 'is_active' => true]);
        $user->roles()->attach(Role::where('slug', $role)->firstOrFail());

        return $this->postJson('/api/login', ['username' => 'u_'.$role, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    public function test_human_login_token_cannot_call_sync_agent_endpoints(): void
    {
        $token = $this->loginToken('karyawan');

        $this->withToken($token)->postJson('/api/agent/sync/sessions')->assertForbidden();
    }

    public function test_legacy_wildcard_token_cannot_call_sync_agent_endpoints(): void
    {
        // Tokens issued before this fix carry ['*'] — they must be refused too.
        $user = User::factory()->create();
        $token = $user->createToken('old-spa-token')->plainTextToken;
        $this->assertSame(['*'], PersonalAccessToken::findToken($token)->abilities);

        $this->withToken($token)->postJson('/api/agent/sync/sessions')->assertForbidden();
    }

    public function test_agent_token_still_works(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('sync-agent', ['agent:sync'])->plainTextToken;

        $this->withToken($token)->postJson('/api/agent/sync/sessions')->assertSuccessful();
    }

    public function test_human_login_token_still_works_for_the_app(): void
    {
        $token = $this->loginToken('admin_gudang');

        $this->withToken($token)->getJson('/api/me')->assertOk();
        $this->withToken($token)->getJson('/api/sync/history')->assertOk();
    }

    public function test_human_login_token_expires(): void
    {
        $token = $this->loginToken('karyawan');
        $expiresAt = PersonalAccessToken::findToken($token)->expires_at;

        $this->assertNotNull($expiresAt);
        $this->travelTo($expiresAt->copy()->addMinute());
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }
}
