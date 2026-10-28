<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Frees the "ppb" name for the real Accurate REQUISITION/REQUISITIONDET mirror
 * (see AccurateSyncService::syncPpb()) — same move as npbg -> goods_issues.
 * FK columns (ppb_id, ppb_item_id) on purchase_orders/purchase_order_items/receivings
 * are kept as-is; MySQL updates the FK metadata to the new table names automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('ppb_amendments', 'purchase_proposal_amendments');
        Schema::rename('ppb_items', 'purchase_proposal_items');
        Schema::rename('ppb', 'purchase_proposals');
    }

    public function down(): void
    {
        Schema::rename('purchase_proposals', 'ppb');
        Schema::rename('purchase_proposal_items', 'ppb_items');
        Schema::rename('purchase_proposal_amendments', 'ppb_amendments');
    }
};
