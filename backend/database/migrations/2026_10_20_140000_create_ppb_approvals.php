<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PPB approval log: every PPB (document, by no_ppb) dated from the go-live day
 * must be approved before it goes to PO. Append-only log — the PPB's approval
 * status is its latest APPROVE / REJECT entry (App\Services\PpbApprovalService).
 * Permission ppb.approve is new and given to super_admin only; the admin grants
 * it to other roles in Role & Hak Akses (additive, no RbacSeeder re-run).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppb_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('no_ppb', 60)->index();
            // DIAJUKAN (system: PPB came in) / APPROVE / REJECT / PO_TANPA_APPROVAL (system: Accurate made a PO first)
            $table->string('action', 20);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 150)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['no_ppb', 'action']);
        });

        $now = now();
        $id = DB::table('permissions')->where('slug', 'ppb.approve')->value('id')
            ?? DB::table('permissions')->insertGetId(['slug' => 'ppb.approve', 'name' => 'Approve / tolak PPB sebelum jadi PO', 'group' => 'ppb', 'created_at' => $now, 'updated_at' => $now]);
        foreach (DB::table('roles')->where('slug', 'super_admin')->pluck('id') as $roleId) {
            if (! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $id])->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', 'ppb.approve')->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        Schema::dropIfExists('ppb_approvals');
    }
};
