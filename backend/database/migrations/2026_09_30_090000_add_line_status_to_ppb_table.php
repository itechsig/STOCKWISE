<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line PPB purchase status as the "1. PPB - RI" Excel computes it
 * (REQUESTED / SHORTAGE / COMPLETED / CLOSE / AMEND / ERROR) — see
 * App\Support\PpbLineStatus. `status` keeps Accurate's own header flag
 * (OPEN / CLOSED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppb', function (Blueprint $table) {
            $table->string('line_status', 12)->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ppb', function (Blueprint $table) {
            $table->dropColumn('line_status');
        });
    }
};
