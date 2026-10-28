<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency key for AccurateSyncService::deriveUsedReturnsFromRi() — one
 * Accurate AP invoice (accurate_apinvoice_id, from the `ri` mirror table)
 * becomes at most one UsedReturn header, so a repeat sync never duplicates
 * it. Deliberately NOT a FK to `ri` (that table has one row per line, many
 * rows share the same invoice id — this stores the raw Accurate id, same
 * spirit as Npbg's own accurate_arinvoice_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('used_returns', function (Blueprint $table) {
            $table->unsignedInteger('accurate_apinvoice_id')->nullable()->unique()->after('npbg_ref_raw');
        });
    }

    public function down(): void
    {
        Schema::table('used_returns', function (Blueprint $table) {
            $table->dropColumn('accurate_apinvoice_id');
        });
    }
};
