<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A manual NPBG ("Tambah NPBG", not from a Request Barang) is approved Manager → Direktur before the goods
 * leave the stock (App\Services\NpbgApprovalService). Permissions the additive way: super_admin gets both,
 * Manager / Direktur their step.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'npbg.approve_manager' => 'Approval Manager: NPBG manual',
        'npbg.approve_director' => 'Approval Direktur: NPBG manual (setelah Manager)',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('npbg_approvals')) {
            Schema::create('npbg_approvals', function (Blueprint $table) {
                $table->id();
                $table->string('no_npbg', 60)->index();
                // APPROVE_MANAGER / APPROVE / REJECT
                $table->string('action', 20);
                $table->text('note')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('user_name', 150)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
        $now = now();
        $ids = [];
        foreach (self::PERMISSIONS as $slug => $name) {
            $ids[$slug] = DB::table('permissions')->where('slug', $slug)->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => $slug, 'name' => $name, 'group' => 'npbg', 'created_at' => $now, 'updated_at' => $now]);
        }
        $grant = function (int $roleId, string $slug) use ($ids) {
            if (! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $ids[$slug]])->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $ids[$slug]]);
            }
        };
        $view = DB::table('permissions')->where('slug', 'npbg.view')->value('id');
        foreach (['super_admin' => array_keys(self::PERMISSIONS), 'manager' => ['npbg.approve_manager'], 'direktur' => ['npbg.approve_director']] as $role => $slugs) {
            foreach (DB::table('roles')->where('slug', $role)->pluck('id') as $roleId) {
                foreach ($slugs as $slug) {
                    $grant($roleId, $slug);
                }
                if ($view && ! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $view])->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $view]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('npbg_approvals');
    }
};
