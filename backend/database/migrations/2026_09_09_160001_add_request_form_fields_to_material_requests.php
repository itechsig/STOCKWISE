<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->string('requester_name', 150)->nullable()->after('requester_id');
            $table->date('request_date')->nullable()->after('purpose');
            // Diisi admin gudang (bukan peminta) — nomor NPBG & PPB terkait.
            $table->string('npbg_no', 40)->nullable()->after('status');
            $table->string('ppb_no', 40)->nullable()->after('npbg_no');
        });

        // Backfill tanggal untuk request lama.
        DB::table('material_requests')->whereNull('request_date')
            ->update(['request_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropColumn(['requester_name', 'request_date', 'npbg_no', 'ppb_no']);
        });
    }
};
