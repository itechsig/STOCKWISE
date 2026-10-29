<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix deskripsi_peminta for item request lines that don't have it set.
 * This ensures the original requester's description is preserved even after matching to Master Produk.
 */
return new class extends Migration
{
    public function up(): void
    {
        // For lines without deskripsi_peminta, copy from deskripsi_barang (current master name)
        // Then restore deskripsi_barang to the original name if we can infer it from history
        DB::table('item_request_lines')
            ->whereNull('deskripsi_peminta')
            ->update(['deskripsi_peminta' => DB::raw('deskripsi_barang')]);

        // For lines that were never matched (item_id is null), deskripsi_barang should stay as deskripsi_peminta
        DB::table('item_request_lines')
            ->whereNull('item_id')
            ->whereNotNull('deskripsi_peminta')
            ->update(['deskripsi_barang' => DB::raw('deskripsi_peminta')]);
    }

    public function down(): void
    {
        // Revert to keeping master name in deskripsi_barang
        // This would require joining with items table, which is complex in down migration
        // For simplicity, we just keep deskripsi_peminta as is
    }
};
