<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO — unlike NPBG/PPB/RI, the internal `purchase_orders` table already
 * represents the real thing (an order sent to a vendor), it just wasn't
 * synced against Accurate's own PO/PODET records. Per user decision, this
 * does NOT get a separate mirror feature: real Accurate POs are synced
 * straight into `purchase_orders`/`purchase_order_items` as additional rows
 * (accurate_po_id/accurate_seq set, read-only), alongside whatever rows the
 * internal DRAFT->APPROVED->SENT->RECEIVED workflow already created there
 * (accurate_po_id null). See AccurateSyncService::syncPo().
 *
 * prefix/year/month/sequence only make sense for the internal numbering
 * scheme (DocumentNumberService) — made nullable so Accurate-synced rows
 * (identified purely by number = real PONO) don't collide on the
 * (prefix,year,month,sequence) unique key; MySQL allows multiple NULLs there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedInteger('accurate_po_id')->nullable()->unique()->after('id');
            $table->timestamp('accurate_synced_at')->nullable()->after('created_by');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('prefix', 12)->nullable()->default(null)->change();
            $table->unsignedSmallInteger('year')->nullable()->change();
            $table->unsignedTinyInteger('month')->nullable()->change();
            $table->unsignedInteger('sequence')->nullable()->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unsignedInteger('accurate_seq')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('accurate_seq');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['accurate_po_id', 'accurate_synced_at']);
            $table->string('prefix', 12)->default('BL')->nullable(false)->change();
            $table->unsignedSmallInteger('year')->nullable(false)->change();
            $table->unsignedTinyInteger('month')->nullable(false)->change();
            $table->unsignedInteger('sequence')->nullable(false)->change();
        });
    }
};
