<?php

namespace Tests\Feature\Accurate;

use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AgentRunTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = RbacSeeder::class;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agentrun_'.uniqid();
        mkdir($this->dir);
        config(['stockwise.agent_log_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function writeStatus(array $s): void
    {
        file_put_contents($this->dir.'/agent-status.json', json_encode($s));
    }

    private function fakeTasklist(string $output): void
    {
        Process::fake(fn (PendingProcess $p) => Process::result(
            str_contains(implode(' ', (array) $p->command), 'tasklist') ? $output : ''
        ));
    }

    public function test_permissions(): void
    {
        $this->postJson('/api/agent-runs', ['mode' => 'snapshot'])->assertUnauthorized();
        $this->actingAsRole('karyawan');
        $this->postJson('/api/agent-runs', ['mode' => 'snapshot'])->assertForbidden();
        $this->getJson('/api/agent-runs/status')->assertForbidden();
    }

    public function test_starts_the_agent_detached_with_the_chosen_mode(): void
    {
        $commands = [];
        Process::fake(function (PendingProcess $p) use (&$commands) {
            $commands[] = implode(' ', (array) $p->command);

            return Process::result('');
        });
        $this->actingAsRole('admin_gudang');

        $this->postJson('/api/agent-runs', ['mode' => 'snapshot'])->assertStatus(202)->assertJsonPath('data.mode', 'snapshot');
        $this->postJson('/api/agent-runs', ['mode' => 'full'])->assertStatus(202);

        $this->assertStringContainsString("'agent.run','--snapshot'", $commands[0]);
        $this->assertStringContainsString("'agent.run','--full'", $commands[1]);
        $this->assertStringContainsString('Start-Process', $commands[0]);
        $this->postJson('/api/agent-runs', ['mode' => 'sync-now'])->assertUnprocessable();
    }

    public function test_refuses_while_a_job_is_really_running(): void
    {
        $this->writeStatus(['running' => true, 'mode' => 'full', 'pid' => 4242, 'started_at' => '2026-09-26T21:00:00+07:00']);
        $this->fakeTasklist('"python.exe","4242","Services","0","50,000 K"');
        $this->actingAsRole('admin_gudang');

        $this->getJson('/api/agent-runs/status')->assertOk()->assertJsonPath('data.running', true)->assertJsonPath('data.mode', 'full');
        $this->postJson('/api/agent-runs', ['mode' => 'snapshot'])->assertStatus(409);
    }

    public function test_a_dead_job_is_reported_as_interrupted_and_does_not_block(): void
    {
        $this->writeStatus(['running' => true, 'mode' => 'full', 'pid' => 4242, 'started_at' => '2026-09-26T21:00:00+07:00']);
        $this->fakeTasklist('INFO: No tasks are running which match the specified criteria.');
        $this->actingAsRole('admin_gudang');

        $this->getJson('/api/agent-runs/status')->assertOk()->assertJsonPath('data.running', false)->assertJsonPath('data.result', 'interrupted');
        $this->postJson('/api/agent-runs', ['mode' => 'snapshot'])->assertStatus(202);
    }
}
