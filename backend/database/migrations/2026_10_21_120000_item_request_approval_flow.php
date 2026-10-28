<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Request Barang → NPBG approval flow (App\Services\ItemRequestApprovalService):
 *   admin validates → Manager → Direktur → Lapangan Gudang (barang siap)
 *   → serah terima (foto bukti + tanda tangan penerima) → SELESAI.
 * - item_requests.status widened for the new stages; track_token lets the requester
 *   follow the request without an account (Cek Permintaan); handover columns.
 * - item_request_approvals: append-only log of every step.
 * - permissions approve_manager / approve_director / handover (additive, no RbacSeeder
 *   re-run) and the new roles Manager / Direktur; super_admin gets all three,
 *   lapangan_gudang gets handover. Other roles are set by the admin in Role & Hak Akses.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'item_request.approve_manager' => 'Approval Manager: setujui / tolak NPBG dari request barang',
        'item_request.approve_director' => 'Approval Direktur: setujui / tolak NPBG dari request barang',
        'item_request.handover' => 'Lapangan gudang: siapkan barang & serah terima (foto + tanda tangan)',
    ];

    private const ROLES = [
        'manager' => ['Manager', 'Approval Manager untuk NPBG dari request barang.', ['item_request.approve_manager']],
        'direktur' => ['Direktur', 'Approval Direktur untuk NPBG dari request barang (setelah Manager).', ['item_request.approve_director']],
    ];

    public function up(): void
    {
        Schema::table('item_requests', function (Blueprint $table) {
            $table->string('status', 20)->default('MENUNGGU')->change();
            $table->string('track_token', 40)->nullable()->unique()->after('number');
            $table->timestamp('handed_over_at')->nullable();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by_name', 150)->nullable();
            $table->string('handover_note', 255)->nullable();
        });
        foreach (DB::table('item_requests')->whereNull('track_token')->pluck('id') as $id) {
            DB::table('item_requests')->where('id', $id)->update(['track_token' => Str::random(32)]);
        }

        Schema::create('item_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_request_id')->constrained('item_requests')->cascadeOnDelete();
            // ADMIN / MANAGER / DIREKTUR / GUDANG / SERAH_TERIMA
            $table->string('stage', 20);
            // VALIDASI / APPROVE / REJECT / HANDOVER
            $table->string('action', 20);
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 150)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['item_request_id', 'stage']);
        });

        $now = now();
        $ids = [];
        foreach (self::PERMISSIONS as $slug => $name) {
            $ids[$slug] = DB::table('permissions')->where('slug', $slug)->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => $slug, 'name' => $name, 'group' => 'item_request', 'created_at' => $now, 'updated_at' => $now]);
        }
        $grant = function (int $roleId, string $slug) use ($ids) {
            if (! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $ids[$slug]])->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $ids[$slug]]);
            }
        };
        foreach (DB::table('roles')->where('slug', 'super_admin')->pluck('id') as $roleId) {
            foreach (array_keys(self::PERMISSIONS) as $slug) {
                $grant($roleId, $slug);
            }
        }
        foreach (DB::table('roles')->where('slug', 'lapangan_gudang')->pluck('id') as $roleId) {
            $grant($roleId, 'item_request.handover');
        }
        $basic = DB::table('permissions')->whereIn('slug', ['profile.view_own', 'profile.update_own', 'notification.view_own'])->pluck('id');
        foreach (self::ROLES as $slug => [$name, $description, $perms]) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id')
                ?? DB::table('roles')->insertGetId(['slug' => $slug, 'name' => $name, 'description' => $description, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($perms as $p) {
                $grant($roleId, $p);
            }
            foreach ($basic as $permissionId) {
                if (! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }
    }

    public function down(): void
    {
        $roleIds = DB::table('roles')->whereIn('slug', array_keys(self::ROLES))->pluck('id');
        DB::table('role_user')->whereIn('role_id', $roleIds)->delete();
        DB::table('permission_role')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('item_request_approvals');
        Schema::table('item_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handed_over_by');
            $table->dropUnique(['track_token']);
            $table->dropColumn(['track_token', 'handed_over_at', 'received_by_name', 'handover_note']);
        });
    }
};
