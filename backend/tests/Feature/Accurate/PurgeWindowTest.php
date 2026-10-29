<?php

namespace Tests\Feature\Accurate;

use App\Models\SyncBatch;
use App\Models\User;
use App\Services\Accurate\AccurateSyncService;
use App\Services\Accurate\StockwisePurge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** STOCKWISE data is removed by the night sync only (WIB), the day's syncs keep it. */
class PurgeWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_night_sync_purges(): void
    {
        config(['stockwise.accurate_only_purge_window' => '18:00-06:00', 'stockwise.accurate_only' => true]);
        $wib = fn (string $t) => Carbon::parse("2026-10-29 {$t}", 'Asia/Jakarta')->utc();
        foreach (['20:40', '23:59', '00:30', '05:59', '18:00'] as $t) {
            $this->assertTrue(StockwisePurge::isNight($wib($t)), $t);
        }
        foreach (['06:00', '09:30', '12:10', '17:59'] as $t) {
            $this->assertFalse(StockwisePurge::isNight($wib($t)), $t);
        }

        $scheduled = new SyncBatch(['created_by' => null]);
        $manual = new SyncBatch(['created_by' => 7]);
        Carbon::setTestNow($wib('12:10'));
        $this->assertFalse(StockwisePurge::dueNow($scheduled));
        Carbon::setTestNow($wib('20:40'));
        $this->assertTrue(StockwisePurge::dueNow($scheduled));
        // a manual sync (button / Agent started from the web) never removes test data
        $this->assertFalse(StockwisePurge::dueNow($manual));
        config(['stockwise.accurate_only' => false]);
        $this->assertFalse(StockwisePurge::dueNow($scheduled));
        Carbon::setTestNow();
    }

    public function test_the_agent_started_from_the_web_makes_a_manual_batch(): void
    {
        $user = User::factory()->create();
        Cache::put(StockwisePurge::MANUAL_AGENT_RUN, $user->id, now()->addHours(2));
        $this->assertSame($user->id, app(AccurateSyncService::class)->startForAgent()->created_by);
        SyncBatch::query()->update(['status' => 'SUCCESS']);
        // the scheduled one after it: no user
        $this->assertNull(app(AccurateSyncService::class)->startForAgent()->created_by);
    }
}
