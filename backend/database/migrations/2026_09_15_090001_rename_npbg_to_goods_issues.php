<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Frees the `npbg`/`npbg_items` table names for the real NPBG (Accurate ARINV/ARINVDET
 * mirror, see create_npbg_table below). The pickup-from-request workflow these tables
 * actually implement is a distinct document (goods issued against a warehouse
 * reservation) — renamed rather than dropped, so Request/Tracking history and
 * behavior are unaffected. MySQL's RENAME TABLE updates existing FK metadata on
 * dependent tables (tracking `*npbg_id` columns) to point at the new name automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('npbg', 'goods_issues');
        Schema::rename('npbg_items', 'goods_issue_items');
    }

    public function down(): void
    {
        Schema::rename('goods_issue_items', 'npbg_items');
        Schema::rename('goods_issues', 'npbg');
    }
};
