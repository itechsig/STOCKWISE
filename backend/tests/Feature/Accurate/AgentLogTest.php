<?php

namespace Tests\Feature\Accurate;

use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/sync/agent-log — read-only view of the Sync Agent's sync.log /
 * agent.log, including runs that never reached the API (sync_batches).
 */
class AgentLogTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = RbacSeeder::class;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agentlog_'.uniqid();
        mkdir($this->dir);
        config(['stockwise.agent_log_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_requires_sync_view_permission(): void
    {
        $this->getJson('/api/sync/agent-log')->assertUnauthorized();

        $this->actingAsRole('karyawan');
        $this->getJson('/api/sync/agent-log')->assertForbidden();
    }

    public function test_parses_runs_and_events_newest_first_with_multiline_errors(): void
    {
        file_put_contents($this->dir.'/sync.log', implode("\n", [
            '2026-09-23T09:04:04.556897 backup=GUDANGSIG2025 Tuesday.gbk status=FAILED error=Stockwise API returned 500: {',
            '    "message": "Server Error"',
            '}',
            '2026-09-25T10:35:01.908431 backup=GUDANGSIG2025 092026.gbk status=PARTIAL error=-',
            '2026-09-25T21:00:05.000000 backup=LIVE-20260925_210000 status=STAGED error=-',
        ])."\n");
        file_put_contents($this->dir.'/agent.log', implode("\n", [
            '2026-09-25 08:44:19,181 INFO: Candidate backup: GUDANGSIG2025 092026.gbk (37.7 MB)',
            '2026-09-25 08:50:00,000 ERROR: Sync failed for X',
            'Traceback (most recent call last):',
            '  File "run.py", line 1, in <module>',
        ])."\n");

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson('/api/sync/agent-log')->assertOk();

        $runs = $res->json('data.runs');
        $this->assertCount(3, $runs);
        $this->assertSame('STAGED', $runs[0]['status']);
        $this->assertSame('LIVE-20260925_210000', $runs[0]['source']);
        $this->assertNull($runs[0]['error']);
        $this->assertSame('FAILED', $runs[2]['status']);
        $this->assertStringContainsString('"message": "Server Error"', $runs[2]['error']);

        $events = $res->json('data.events');
        $this->assertCount(2, $events);
        $this->assertSame('ERROR', $events[0]['level']);
        $this->assertSame('2026-09-25T08:50:00', $events[0]['time']);
        $this->assertTrue($res->json('data.log_available'));
    }

    public function test_redacts_secrets(): void
    {
        file_put_contents($this->dir.'/sync.log',
            '2026-09-25T10:00:00.0 backup=X status=FAILED error=login failed password=hunter2 token: abc123 Bearer 5|xyzSECRET'."\n");

        $this->actingAsRole('admin_gudang');
        $error = $this->getJson('/api/sync/agent-log')->assertOk()->json('data.runs.0.error');

        $this->assertStringNotContainsString('hunter2', $error);
        $this->assertStringNotContainsString('abc123', $error);
        $this->assertStringNotContainsString('xyzSECRET', $error);
    }

    public function test_missing_log_folder_returns_empty_lists(): void
    {
        config(['stockwise.agent_log_path' => $this->dir.DIRECTORY_SEPARATOR.'nope']);

        $this->actingAsRole('admin_gudang');
        $this->getJson('/api/sync/agent-log')->assertOk()
            ->assertJsonPath('data.runs', [])
            ->assertJsonPath('data.events', [])
            ->assertJsonPath('data.log_available', false);
    }
}
