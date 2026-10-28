<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Return (Retur Pembelian): goods received on an RI go back to the vendor
 * (App\Services\PurchaseReturnService). Made in STOCKWISE only — Accurate's APRETURN
 * is unused and nothing is written to Accurate.
 *   DIBUAT → DIKIRIM (sent back to the vendor) → SELESAI (replaced / credited), or DIBATALKAN.
 * Lines keep a copy of the RI line (an RI line can change or disappear on sync).
 * Permissions purchase_return.view / .manage are new and given to super_admin only;
 * the admin grants them to other roles in Role & Hak Akses (additive, no RbacSeeder re-run).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'purchase_return.view' => 'Lihat retur pembelian',
        'purchase_return.manage' => 'Buat & kelola retur pembelian (kirim ke vendor, selesaikan, batalkan)',
    ];

    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->date('tgl_retur');
            $table->string('no_ri', 60)->index();
            $table->string('no_po', 60)->nullable();
            $table->string('vendor', 200)->nullable()->index();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            // GANTI_BARANG (the vendor sends replacements) / POTONG_TAGIHAN (credit / refund)
            $table->string('resolution', 20);
            $table->string('reason', 255);
            $table->text('note')->nullable();
            // DIBUAT / DIKIRIM / SELESAI / DIBATALKAN
            $table->string('status', 12)->default('DIBUAT')->index();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_note', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            // the replacement RI number, or the credit note number
            $table->string('resolution_ref', 100)->nullable();
            $table->string('resolution_note', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('ri_id')->nullable()->constrained('ri')->nullOnDelete();
            $table->string('kode_barang', 60)->nullable();
            $table->string('deskripsi_barang', 400)->nullable();
            $table->decimal('qty', 14, 2);
            $table->string('satuan', 30)->nullable();
            $table->decimal('harga_satuan', 16, 2)->nullable();
            // received on the RI when the return was made
            $table->decimal('ri_qty', 14, 2)->nullable();
            $table->timestamps();
            $table->index('ri_id');
        });

        $now = now();
        foreach (self::PERMISSIONS as $slug => $name) {
            $id = DB::table('permissions')->where('slug', $slug)->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => $slug, 'name' => $name, 'group' => 'purchase_return', 'created_at' => $now, 'updated_at' => $now]);
            foreach (DB::table('roles')->where('slug', 'super_admin')->pluck('id') as $roleId) {
                if (! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $id])->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $id]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
    }
};
