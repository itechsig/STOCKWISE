<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One PO number, items from different vendors (a STOCKWISE PO made from a PPB): the vendor of each
 * line. Null = the PO's own vendor (every Accurate PO, and the lines made before this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('purchase_order_id')->constrained('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
