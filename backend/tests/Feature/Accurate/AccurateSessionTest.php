<?php

namespace Tests\Feature\Accurate;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccurateSessionTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = RbacSeeder::class;

    private function probeJson(): string
    {
        return json_encode([
            'generated_at' => '2026-09-26T20:00:00+07:00',
            'db_port' => 3051,
            'connections' => [
                ['kind' => 'database', 'local_address' => '192.168.2.41', 'local_port' => 3051, 'ip' => '192.168.2.15', 'remote_port' => 50001, 'since' => '2026-09-26T08:01:00+07:00'],
                ['kind' => 'database', 'local_address' => '192.168.2.41', 'local_port' => 3051, 'ip' => '192.168.2.15', 'remote_port' => 50002, 'since' => '2026-09-26T08:05:00+07:00'],
                ['kind' => 'license', 'local_address' => '192.168.2.41', 'local_port' => 6688, 'ip' => '192.168.2.15', 'remote_port' => 50003, 'since' => '2026-09-26T08:00:30+07:00'],
                ['kind' => 'license', 'local_address' => '192.168.2.41', 'local_port' => 6688, 'ip' => '192.168.2.22', 'remote_port' => 50100, 'since' => '2026-09-26T09:00:00+07:00'],
            ],
            'hosts' => [
                '192.168.2.15' => ['hostname' => 'GUDANG-PC1', 'mac' => '00-11-22-33-44-55'],
                '192.168.2.22' => ['hostname' => null, 'mac' => 'AA-BB-CC-DD-EE-FF'],
            ],
        ]);
    }

    private function fakeProcesses(array &$killCalls): void
    {
        Process::fake(function (PendingProcess $p) use (&$killCalls) {
            $cmd = implode(' ', (array) $p->command);
            if (str_contains($cmd, 'accurate-session-kill.ps1')) {
                $killCalls[] = $cmd;

                return Process::result(json_encode(['ok' => true, 'code' => 'terminated', 'message' => 'ok']));
            }

            return Process::result($this->probeJson());
        });
    }

    private function actingWith(array $permissions): void
    {
        $role = Role::create(['slug' => 'viewer_x', 'name' => 'Viewer X', 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id'));
        $u = User::factory()->create(['is_active' => true]);
        $u->roles()->attach($role);
        Sanctum::actingAs($u);
    }

    public function test_requires_permissions(): void
    {
        $this->getJson('/api/accurate/sessions')->assertUnauthorized();
        $this->actingAsRole('karyawan');
        $this->getJson('/api/accurate/sessions')->assertForbidden();
        $this->postJson('/api/accurate/sessions/terminate', ['remote_address' => '192.168.2.15'])->assertForbidden();
    }

    public function test_lists_clients_grouped_per_pc_with_details(): void
    {
        $calls = [];
        $this->fakeProcesses($calls);
        $this->actingWith(['accurate.session.view']);

        $res = $this->getJson('/api/accurate/sessions')->assertOk();

        $res->assertJsonPath('data.summary.clients', 2)
            ->assertJsonPath('data.summary.clients_using_database', 1)
            ->assertJsonPath('data.summary.db_connections', 2)
            ->assertJsonPath('data.clients.0.ip', '192.168.2.15')
            ->assertJsonPath('data.clients.0.hostname', 'GUDANG-PC1')
            ->assertJsonPath('data.clients.0.mac', '00-11-22-33-44-55')
            ->assertJsonPath('data.clients.0.status', 'using_database')
            ->assertJsonPath('data.clients.0.since', '2026-09-26T08:00:30+07:00')
            ->assertJsonPath('data.clients.1.status', 'license_only');
        $this->assertCount(3, $res->json('data.clients.0.connections'));

        // view permission alone cannot disconnect
        $this->postJson('/api/accurate/sessions/terminate', ['remote_address' => '192.168.2.15'])->assertForbidden();
    }

    public function test_terminate_one_connection_or_all_database_connections_of_a_pc(): void
    {
        $calls = [];
        $this->fakeProcesses($calls);
        $this->actingWith(['accurate.session.view', 'accurate.session.terminate']);

        $this->postJson('/api/accurate/sessions/terminate', ['remote_address' => '192.168.2.15', 'remote_port' => 50002])
            ->assertOk()->assertJsonPath('data.terminated', 1);
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('-RemotePort 50002', $calls[0]);
        $this->assertStringContainsString('-LocalPort 3051', $calls[0]);

        $calls = [];
        $this->postJson('/api/accurate/sessions/terminate', ['remote_address' => '192.168.2.15'])
            ->assertOk()->assertJsonPath('data.terminated', 2);
        $this->assertCount(2, $calls, 'only the 2 database connections, never the license one');
    }

    public function test_terminate_refuses_license_only_pc_server_itself_and_bad_input(): void
    {
        $calls = [];
        $this->fakeProcesses($calls);
        $this->actingWith(['accurate.session.view', 'accurate.session.terminate']);

        $this->postJson('/api/accurate/sessions/terminate', ['remote_address' => '192.168.2.22'])->assertStatus(422);
        $this->postJson('/api/accurate/sessions/terminate', ['remote_address' => '192.168.2.41'])->assertStatus(422);
        $this->postJson('/api/accurate/sessions/terminate', ['remote_address' => '127.0.0.1'])->assertStatus(422);
        $this->postJson('/api/accurate/sessions/terminate', ['remote_address' => 'bukan-ip'])->assertStatus(422);
        $this->assertSame([], $calls);
    }

    public function test_super_admin_has_both_permissions_via_migration(): void
    {
        $this->actingAsRole('super_admin');
        $this->assertTrue(auth()->user()->hasPermission('accurate.session.terminate'));
    }

    public function test_user_name_division_and_note_per_ip_are_saved_and_shown_when_online(): void
    {
        $calls = [];
        $this->fakeProcesses($calls);
        $this->actingWith(['accurate.session.view', 'accurate.session.terminate']);

        // can be filled in for a PC that is not online yet
        $this->putJson('/api/accurate/ip-labels', ['ip' => '192.168.2.22', 'user_name' => 'Budi', 'division' => 'Gudang', 'note' => 'PC admin'])
            ->assertOk()->assertJsonPath('data.user_name', 'Budi');
        $this->assertDatabaseHas('accurate_ip_labels', ['ip' => '192.168.2.22', 'division' => 'Gudang']);

        $this->getJson('/api/accurate/sessions')->assertOk()
            ->assertJsonPath('data.clients.0.user_name', null)
            ->assertJsonPath('data.clients.1.user_name', 'Budi')
            ->assertJsonPath('data.clients.1.division', 'Gudang')
            ->assertJsonPath('data.clients.1.note', 'PC admin');

        // edit = update the same row
        $this->putJson('/api/accurate/ip-labels', ['ip' => '192.168.2.22', 'user_name' => 'Budi S', 'division' => 'Gudang', 'note' => ''])->assertOk();
        $this->assertDatabaseCount('accurate_ip_labels', 1);
        $this->assertDatabaseHas('accurate_ip_labels', ['ip' => '192.168.2.22', 'user_name' => 'Budi S', 'note' => null]);

        // all empty = removed
        $this->putJson('/api/accurate/ip-labels', ['ip' => '192.168.2.22'])->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseCount('accurate_ip_labels', 0);

        $this->putJson('/api/accurate/ip-labels', ['ip' => 'bukan-ip', 'user_name' => 'x'])->assertStatus(422);
        $this->assertSame([], $calls, 'editing never disconnects anyone');
    }

    public function test_view_permission_cannot_edit_ip_data(): void
    {
        $this->actingWith(['accurate.session.view']);

        $this->putJson('/api/accurate/ip-labels', ['ip' => '192.168.2.22', 'user_name' => 'x'])->assertForbidden();
    }
}
