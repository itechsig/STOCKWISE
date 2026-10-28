<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Kategori Anak 1/2/3, diturunkan dari ITEMDESCRIPTION milik node
            // PARENTITEM hierarki ITEMNO Accurate (bukan dari tree kategori
            // Excel/`categories` — itu tetap ada lewat category_id, tidak
            // disentuh). Tidak ada kolom "kategori_induk": diverifikasi
            // langsung ke GUDANGSIG2025.GDB bahwa node ITEMNO 0-titik (mis.
            // "AUT" polos) tidak pernah ada di data real perusahaan ini, jadi
            // level itu tidak punya sumber sama sekali dan akan selalu NULL —
            // lihat docs/accurate-database-analysis.md §12.
            $table->string('accurate_category_anak_1')->nullable()->after('accurate_qty_onorder');
            $table->string('accurate_category_anak_2')->nullable()->after('accurate_category_anak_1');
            $table->string('accurate_category_anak_3')->nullable()->after('accurate_category_anak_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['accurate_category_anak_1', 'accurate_category_anak_2', 'accurate_category_anak_3']);
        });
    }
};
