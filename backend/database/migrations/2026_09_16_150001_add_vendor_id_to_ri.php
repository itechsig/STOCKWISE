<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RI's vendor was only ever stored as a plain string (`vendor`), unlike PO
 * which already resolves+links a real `vendors` row via
 * AccurateSyncService::findOrCreateAccurateVendor(). Both come from the same
 * Accurate PERSONDATA row (APINV.VENDORID for RI, PO.VENDORID for PO) — when
 * an RI line is chained to a PO line (accurate_po_item_id), it's frequently
 * the very same vendor, so it should resolve to the very same `vendors` row,
 * not just a matching string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ri', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('vendor')
                ->constrained('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ri', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
