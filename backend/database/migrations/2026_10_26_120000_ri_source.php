<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a STOCKWISE RI comes from: PO (a STOCKWISE PO line), BEKAS (used goods coming back, a row of
 * Tracking Pengembalian Bekas "Spare Part Lain") or MANUAL (anything else). Older rows stay null:
 * RiResource::source() reads them from their PO link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ri', function (Blueprint $table) {
            $table->string('sumber', 10)->nullable()->index()->after('divisi');
            $table->unsignedBigInteger('used_return_row_id')->nullable()->index()->after('sumber');
        });
    }

    public function down(): void
    {
        Schema::table('ri', function (Blueprint $table) {
            $table->dropIndex(['sumber']);
            $table->dropIndex(['used_return_row_id']);
            $table->dropColumn(['sumber', 'used_return_row_id']);
        });
    }
};
