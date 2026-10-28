<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Request Barang: a karyawan asks for items, admin gudang decides per line —
 * NPBG when the stock is there, PPB when it is not (or rejects the line).
 * New tables (item_requests / item_request_lines), unrelated to the removed
 * material_requests feature. Permissions are added the additive way (see
 * 2026_09_26_130000_add_accurate_session_permissions): never by re-running
 * RbacSeeder, which would reset every role's permissions.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'item_request.create' => 'Buat & lihat request barang sendiri',
        'item_request.view' => 'Lihat semua request barang',
        'item_request.process' => 'Proses request barang (jadikan NPBG / PPB / tolak)',
    ];

    public function up(): void
    {
        // share links: anyone holding an active link can SEND a request (no account, nothing to read);
        // admin turns each link on / off, or deletes it (= a new link to hand out)
        Schema::create('item_request_links', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('label', 100)->nullable(); // e.g. "Maintenance", "Semua karyawan"
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('item_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('source', 8)->default('APP'); // APP (logged in) / LINK (share link)
            $table->foreignId('link_id')->nullable()->constrained('item_request_links')->nullOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name', 150)->nullable();
            $table->string('requester_contact', 60)->nullable(); // phone / ext, for a link request
            $table->string('requester_ip', 45)->nullable();
            $table->string('requester_location', 10)->nullable(); // IpClassifier: OFFICE / EXTERNAL / UNKNOWN
            $table->string('divisi', 100)->nullable();
            $table->date('needed_date')->nullable();
            $table->text('note')->nullable();
            // MENUNGGU -> DIPROSES (some lines decided) -> SELESAI / DITOLAK; DIBATALKAN by the requester
            $table->string('status', 12)->default('MENUNGGU')->index();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('item_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_request_id')->constrained('item_requests')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('kode_barang', 60)->nullable();
            $table->string('deskripsi_barang', 400);
            $table->decimal('qty', 12, 2);
            $table->string('satuan', 30)->nullable();
            $table->string('note', 255)->nullable();
            $table->string('decision', 8)->nullable()->index(); // NPBG / PPB / TOLAK
            $table->string('reject_reason', 255)->nullable();
            $table->foreignId('npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->foreignId('ppb_id')->nullable()->constrained('ppb')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        $ids = [];
        foreach (self::PERMISSIONS as $slug => $name) {
            $ids[$slug] = DB::table('permissions')->where('slug', $slug)->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => $slug, 'name' => $name, 'group' => 'item_request', 'created_at' => $now, 'updated_at' => $now]);
        }

        // super_admin + every role that can already make a manual NPBG (the admin gudang roles,
        // system or custom) get all three; the karyawan role gets create.
        $adminRoles = DB::table('roles')->where('slug', 'super_admin')->pluck('id')
            ->merge(DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permissions.slug', 'npbg.create')->pluck('permission_role.role_id'))
            ->unique();
        $grants = [];
        foreach ($adminRoles as $roleId) {
            foreach ($ids as $permissionId) {
                $grants[] = [$roleId, $permissionId];
            }
        }
        foreach (DB::table('roles')->where('slug', 'karyawan')->pluck('id') as $roleId) {
            $grants[] = [$roleId, $ids['item_request.create']];
        }
        foreach ($grants as [$roleId, $permissionId]) {
            if (! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('item_request_lines');
        Schema::dropIfExists('item_requests');
        Schema::dropIfExists('item_request_links');
    }
};
