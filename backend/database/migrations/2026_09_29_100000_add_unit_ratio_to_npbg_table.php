<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARINVDET.UNITRATIO: how many base units (items.unit) one transaction unit
 * holds — e.g. 1 SET = 2 PCS. Accurate itself moves stock by
 * QUANTITY × UNITRATIO (verified against ITEMHIST for every NPBG line), so
 * usage averages must use kuantitas × unit_ratio; `kuantitas` stays the
 * figure as typed in Accurate (what users recognise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('npbg', function (Blueprint $table) {
            $table->decimal('unit_ratio', 14, 4)->default(1)->after('satuan');
        });
    }

    public function down(): void
    {
        Schema::table('npbg', function (Blueprint $table) {
            $table->dropColumn('unit_ratio');
        });
    }
};
