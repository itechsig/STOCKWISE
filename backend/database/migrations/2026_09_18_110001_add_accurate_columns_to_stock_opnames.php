<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock Opname — same situation as PO: `stock_opnames`/`stock_opname_items`
 * already represent the real thing (a physical count reconciled against
 * system qty), just not yet synced against Accurate's own record of it.
 * Accurate's ITEMADJ (header) + ITADJDET (lines) — confirmed against real
 * data, description literally says "STOK OPNAME TGL 10.09.2026" — is that
 * record. Real ITEMADJ rows are synced straight into these tables as
 * additional rows (accurate_itemadj_id set), alongside whatever the internal
 * SCHEDULED->IN_PROGRESS->PENDING_REVIEW->COMPLETED workflow already created
 * (accurate_itemadj_id null). No line-level accurate_seq column is needed:
 * stock_opname_items already has unique(stock_opname_id, item_id), which is
 * exactly the right upsert key here too (one line per item per opname).
 *
 * Unlike PO, Accurate's own stock effect (ITEMADJ) is NOT re-applied to our
 * stock_movements/inventory ledger — same rule as every other Accurate sync
 * in this app (Accurate's qty is reference-only, see AccurateSyncService's
 * class docblock). This only mirrors the opname record itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->unsignedInteger('accurate_itemadj_id')->nullable()->unique()->after('id');
            $table->timestamp('accurate_synced_at')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropColumn(['accurate_itemadj_id', 'accurate_synced_at']);
        });
    }
};
