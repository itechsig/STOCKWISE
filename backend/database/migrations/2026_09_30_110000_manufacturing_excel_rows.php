<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manufaktur & Assembly follows the Excel "7. Tracking Manufaktur & Assembly"
 * row by row: every sub row is one unit with its own Hasil Produk (the MJ sheet
 * mixes products under one number) and its Foto Hasil — the photo is part of
 * the Excel COMPLETED rule (foto + tgl selesai + no. RI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manufacturing_order_subs', function (Blueprint $table) {
            $table->string('product_name', 200)->nullable()->after('item_no');
            $table->string('photo_path', 255)->nullable()->after('ri_no');
            // Excel: one SUB-01 holds many unit rows (SUB-02 = next NPBG batch of
            // the same number) — sub_no is a group, no longer unique per order.
            // The plain index keeps the foreign key covered on MySQL.
            $table->index(['manufacturing_order_id', 'sub_no'], 'mfg_subs_order_sub_idx');
        });
        Schema::table('manufacturing_order_subs', function (Blueprint $table) {
            $table->dropUnique(['manufacturing_order_id', 'sub_no']);
        });
    }

    public function down(): void
    {
        Schema::table('manufacturing_order_subs', function (Blueprint $table) {
            $table->unique(['manufacturing_order_id', 'sub_no']);
        });
        Schema::table('manufacturing_order_subs', function (Blueprint $table) {
            $table->dropIndex('mfg_subs_order_sub_idx');
            $table->dropColumn(['product_name', 'photo_path']);
        });
    }
};
