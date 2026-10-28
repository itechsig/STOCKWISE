<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accurate Online page permissions. Additive only: inserts the two new
 * permission rows and grants them to super_admin (whose frontend menu is
 * driven by its permission rows). Deliberately NOT done by re-running
 * RbacSeeder, which would reset every system role's permissions.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'accurate.session.view' => 'Lihat PC yang sedang terhubung ke Accurate',
        'accurate.session.terminate' => 'Putuskan koneksi PC ke Accurate secara manual',
    ];

    public function up(): void
    {
        $now = now();
        $superAdmin = DB::table('roles')->where('slug', 'super_admin')->value('id');

        foreach (self::PERMISSIONS as $slug => $name) {
            $id = DB::table('permissions')->where('slug', $slug)->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'slug' => $slug, 'name' => $name, 'group' => 'accurate_session',
                    'created_at' => $now, 'updated_at' => $now,
                ]);

            if ($superAdmin && ! DB::table('permission_role')->where(['permission_id' => $id, 'role_id' => $superAdmin])->exists()) {
                DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => $superAdmin]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
