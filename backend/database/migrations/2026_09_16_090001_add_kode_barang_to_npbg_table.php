<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode Barang — ARINVDET.ITEMNO, verbatim (same code space as Master Barang's own
 * `items.code`). NPBG only ever had the free-text `deskripsi_barang` (ITEMOVDESC)
 * before; without the code there's no way to tell which row is which item at a
 * glance, or to cross-reference back to Master Barang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('npbg', function (Blueprint $table) {
            $table->string('kode_barang', 60)->nullable()->after('dikeluarkan_oleh');
            $table->index('kode_barang');
        });
    }

    public function down(): void
    {
        Schema::table('npbg', function (Blueprint $table) {
            $table->dropIndex(['kode_barang']);
            $table->dropColumn('kode_barang');
        });
    }
};
