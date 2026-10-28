<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accurate's own data already chains PPB -> PO -> RI:
 *   REQUISITIONDET (REQID,SEQ) <- PODET.REQID/REQSEQ
 *   PODET (POID,SEQ)           <- APITMDET.POID/POSEQ
 * Verified against real data: an APITMDET row (RI line) with POID/POSEQ
 * resolves to a real PODET row (PO line), which itself resolves via
 * REQID/REQSEQ to a real REQUISITIONDET row (PPB line) — same ITEMNO end to
 * end. This mirrors that chain inside Stockwise as real FKs to our own
 * accurate-mirrored tables, distinct from the *_id columns the internal
 * MaterialRequest -> PurchaseProposal -> PurchaseOrder -> Receiving workflow
 * already uses (ppb_id/ppb_item_id, which point at purchase_proposals, not
 * at the new `ppb` Accurate mirror).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('accurate_ppb_id')->nullable()->after('accurate_seq')
                ->constrained('ppb')->nullOnDelete();
        });

        Schema::table('ri', function (Blueprint $table) {
            $table->foreignId('accurate_po_item_id')->nullable()->after('accurate_seq')
                ->constrained('purchase_order_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ri', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accurate_po_item_id');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accurate_ppb_id');
        });
    }
};
