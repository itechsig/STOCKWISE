<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stpp_transactions.out_npbg_id was originally a FK to the table literally
 * named `npbg` — but 2026_09_15_090001_rename_npbg_to_goods_issues.php
 * renamed that table to `goods_issues` (freeing the `npbg` name for the real
 * Accurate ARINV/ARINVDET mirror) and MySQL's RENAME TABLE moved the FK
 * along with it, so this column has pointed at `goods_issues` — an empty,
 * essentially unused table (0 rows) — ever since, while StppTransaction's
 * own outNpbg() relation was never updated to match and still queries it.
 * STPP's real source of "an item was issued out" is the Accurate NPBG mirror
 * (see AccurateSyncService — 189 real NPBG lines already mention STPP in
 * their keterangan), so this repoints the column at the table that's
 * actually named `npbg` today. Safe: goods_issues has 0 rows, nothing to
 * orphan. Scoped to STPP only, per what was asked — Lend/Borrow/TyreChange/
 * MaintenanceOrderSub/ManufacturingOrderSub/UsedReturn have the identical
 * stale-FK pattern and are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stpp_transactions', function (Blueprint $table) {
            $table->dropForeign(['out_npbg_id']);
            $table->foreign('out_npbg_id')->references('id')->on('npbg')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stpp_transactions', function (Blueprint $table) {
            $table->dropForeign(['out_npbg_id']);
            $table->foreign('out_npbg_id')->references('id')->on('goods_issues')->nullOnDelete();
        });
    }
};
