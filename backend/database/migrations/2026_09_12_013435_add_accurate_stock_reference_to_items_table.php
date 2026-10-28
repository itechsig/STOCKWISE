<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Reference-only snapshot from Accurate's ITEM.QUANTITY/ONORDER — a
            // company-wide total (Accurate has no per-warehouse breakdown for
            // this dataset, see docs/ACCURATE_MAPPING.md). Deliberately NOT fed
            // into the per-warehouse `inventory` table, which stays the sole
            // source of truth for Stockwise's own actual/reserved/available
            // stock. Purely a cross-check reference until stock ownership
            // between the two systems is decided.
            $table->decimal('accurate_qty_onhand', 14, 2)->nullable()->after('accurate_synced_at');
            $table->decimal('accurate_qty_onorder', 14, 2)->nullable()->after('accurate_qty_onhand');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['accurate_qty_onhand', 'accurate_qty_onorder']);
        });
    }
};
