<?php

namespace Tests\Feature\Notifications;

use App\Models\Item;
use App\Models\ItemSafetyStock;
use App\Models\SyncBatch;
use App\Models\User;
use App\Notifications\StockwiseAlert;
use App\Services\Accurate\AccurateSyncService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_requires_permission(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_every_role_can_view_its_own_notifications(): void
    {
        // notification.view_own is granted to every role in Rbac.php — a
        // brand-new role slug that forgets it would silently 403 the bell
        // for that whole role, so this is worth asserting directly rather
        // than trusting the matrix by inspection alone.
        foreach (array_keys(\App\Support\Rbac\Rbac::ROLES) as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/notifications')->assertOk();
            $this->getJson('/api/notifications/unread-count')->assertOk();
        }
    }

    public function test_lists_only_the_authenticated_users_own_notifications_newest_first(): void
    {
        $me = $this->actingAsRole('admin_gudang');
        $other = User::factory()->create();

        $me->notify(new StockwiseAlert('sync', 'danger', 'Punya saya lama', 'body', null));
        $this->travel(1)->seconds();
        $me->notify(new StockwiseAlert('sync', 'success', 'Punya saya baru', 'body', null));
        $other->notify(new StockwiseAlert('sync', 'info', 'Bukan punya saya', 'body', null));

        $res = $this->getJson('/api/notifications')->assertOk();

        $res->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Punya saya baru')
            ->assertJsonPath('data.1.title', 'Punya saya lama');
    }

    public function test_unread_count_and_mark_read_and_mark_all_read(): void
    {
        $me = $this->actingAsRole('admin_gudang');
        $me->notify(new StockwiseAlert('sync', 'danger', 'A', 'body', '/sync/history'));
        $me->notify(new StockwiseAlert('sync', 'warning', 'B', 'body', '/sync/history'));

        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 2);

        $firstId = $me->notifications()->latest()->first()->id;
        $this->postJson("/api/notifications/{$firstId}/read")->assertOk()
            ->assertJsonPath('data.id', $firstId);
        $this->assertNotNull($me->notifications()->find($firstId)->read_at);

        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);

        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 0);
    }

    public function test_cannot_mark_another_users_notification_as_read(): void
    {
        $this->actingAsRole('admin_gudang');
        $other = User::factory()->create();
        $other->notify(new StockwiseAlert('sync', 'danger', 'Bukan punya saya', 'body', null));
        $id = $other->notifications()->first()->id;

        $this->postJson("/api/notifications/{$id}/read")->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Trigger points — spot-check a few real flows actually fan out a
    // notification to the right audience, not just that the infra works
    // in isolation.
    // -----------------------------------------------------------------

    public function test_sync_failure_notifies_users_who_can_view_sync_status(): void
    {
        $viewer = $this->actingAsRole('admin_gudang'); // holds sync.accurate.view
        $karyawan = User::factory()->create();
        $karyawan->roles()->attach(\App\Models\Role::where('slug', 'karyawan')->firstOrFail());

        $batch = SyncBatch::create([
            'sync_code' => 'TEST-001', 'source' => 'accurate', 'started_at' => now(), 'status' => 'RUNNING',
        ]);
        app(AccurateSyncService::class)->failBatch($batch, 'Backup tidak bisa direstore.');

        $this->assertSame(1, $viewer->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $karyawan->fresh()->unreadNotifications()->count());
        $this->assertStringContainsString('Backup tidak bisa direstore.', $viewer->fresh()->unreadNotifications()->first()->data['body']);
    }

    public function test_safety_stock_conflict_notifies_holders_of_resolve_conflict_permission(): void
    {
        // Two separate admin_gudang users: the actor who creates the
        // conflicting row must NOT be notified about their own action —
        // only other holders of item.safety_stock.resolve_conflict should.
        $actor = $this->actingAsRole('admin_gudang');
        $resolver = User::factory()->create();
        $resolver->roles()->attach(\App\Models\Role::where('slug', 'admin_gudang')->firstOrFail());

        $item = Item::factory()->create();
        ItemSafetyStock::create([
            'item_id' => $item->id, 'is_effective' => true, 'needs_review' => false,
            'avg_usage_3m' => 10, 'lead_time_days' => 5, 'safety_stock' => 1, 'min_pr' => 2,
        ]);

        $this->postJson('/api/safety-stocks', [
            'item_id' => $item->id, 'avg_usage_3m' => 20, 'lead_time_days' => 7,
        ])->assertCreated();

        $unread = $resolver->fresh()->unreadNotifications();
        $this->assertSame(1, $unread->count());
        $this->assertStringContainsString($item->code, $unread->first()->data['body']);
        $this->assertSame(0, $actor->fresh()->unreadNotifications()->count());
    }
}
