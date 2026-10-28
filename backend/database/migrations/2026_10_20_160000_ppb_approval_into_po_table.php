<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approved PPB goes straight into the PO table as a "Menunggu PO" entry
 * (PpbApprovalService): no vendor / price yet, so vendor_id may be empty. Its
 * lines point at the PPB through their own column (approved_ppb_id), never
 * accurate_ppb_id — that one stays Accurate's real PO chain, which PPB status,
 * the vendor filter and the dashboards read. The entry is removed by itself
 * once Accurate's PO for those lines is synced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('vendor_id')->nullable()->change();
            // the approver's site when known; an approval must never fail for lack of one
            $table->unsignedBigInteger('site_id')->nullable()->change();
            $table->string('from_ppb_approval', 60)->nullable()->after('notes')->index(); // no_ppb of the approval that made it
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('approved_ppb_id')->nullable()->after('purchase_order_id')->constrained('ppb')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('approved_ppb_id'));
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['from_ppb_approval']);
            $table->dropColumn('from_ppb_approval');
        });
        // vendor_id / site_id stay nullable: entries without them may exist by then
    }
};
