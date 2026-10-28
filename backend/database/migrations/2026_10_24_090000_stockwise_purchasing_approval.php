<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STOCKWISE purchasing (Accurate's own PPB / PO are untouched):
 *  - a STOCKWISE PPB is approved in two steps, Manager then Direktur (PpbApprovalService);
 *  - a STOCKWISE PO is approved by the Direktur (PurchaseOrderService), with its own log;
 *  - Draft PPB is a list of item lines (each from a Request Barang or Safety Stock): lines are
 *    submitted into a PPB one by one or together (ppb_draft_lines.ppb_id);
 *  - permissions added the additive way (never a RbacSeeder re-run): super_admin gets all,
 *    Manager / Direktur their own step (+ what they need to see it).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'ppb.approve_manager' => ['ppb', 'Approval Manager: PPB STOCKWISE'],
        'ppb.approve_director' => ['ppb', 'Approval Direktur: PPB STOCKWISE (setelah Manager)'],
        'po.approve_director' => ['purchasing', 'Approval Direktur: PO STOCKWISE'],
    ];

    private const ROLE_GRANTS = [
        'manager' => ['ppb.approve_manager', 'ppb.view'],
        'direktur' => ['ppb.approve_director', 'ppb.view', 'po.approve_director', 'po.view'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('purchase_order_approvals')) {
            Schema::create('purchase_order_approvals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
                // DIAJUKAN (PO made, waits for the Direktur) / APPROVE / REJECT
                $table->string('action', 20);
                $table->text('note')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('user_name', 150)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasColumn('ppb_draft_lines', 'ppb_id')) {
            Schema::table('ppb_draft_lines', function (Blueprint $table) {
                $table->foreignId('ppb_id')->nullable()->constrained('ppb')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
            });
            // lines of drafts already submitted belong to that PPB
            foreach (DB::table('ppb_drafts')->where('status', 'DIAJUKAN')->whereNotNull('no_ppb')->get(['id', 'no_ppb', 'submitted_at']) as $d) {
                DB::table('ppb_draft_lines')->where('ppb_draft_id', $d->id)
                    ->update(['ppb_id' => DB::table('ppb')->where('no_ppb', $d->no_ppb)->min('id'), 'submitted_at' => $d->submitted_at]);
            }
        }

        $now = now();
        $ids = [];
        foreach (self::PERMISSIONS as $slug => [$group, $name]) {
            $ids[$slug] = DB::table('permissions')->where('slug', $slug)->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => $slug, 'name' => $name, 'group' => $group, 'created_at' => $now, 'updated_at' => $now]);
        }
        $all = DB::table('permissions')->pluck('id', 'slug');
        $grant = function (int $roleId, string $slug) use ($all) {
            $pid = $all[$slug] ?? null;
            if ($pid && ! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $pid])->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
            }
        };
        foreach (DB::table('roles')->where('slug', 'super_admin')->pluck('id') as $roleId) {
            foreach (array_keys(self::PERMISSIONS) as $slug) {
                $grant($roleId, $slug);
            }
        }
        foreach (self::ROLE_GRANTS as $role => $slugs) {
            foreach (DB::table('roles')->where('slug', $role)->pluck('id') as $roleId) {
                foreach ($slugs as $slug) {
                    $grant($roleId, $slug);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('purchase_order_approvals');
        if (Schema::hasColumn('ppb_draft_lines', 'ppb_id')) {
            Schema::table('ppb_draft_lines', function (Blueprint $table) {
                $table->dropConstrainedForeignId('ppb_id');
                $table->dropColumn('submitted_at');
            });
        }
    }
};
