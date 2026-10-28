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
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->enum('action', ['INSERT', 'UPDATE', 'DELETE', 'SKIP', 'ERROR'])->change();
        });

        Schema::table('sync_batches', function (Blueprint $table) {
            $table->unsignedInteger('deleted_records')->default(0)->after('skipped_records');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_batches', function (Blueprint $table) {
            $table->dropColumn('deleted_records');
        });

        Schema::table('sync_logs', function (Blueprint $table) {
            $table->enum('action', ['INSERT', 'UPDATE', 'SKIP', 'ERROR'])->change();
        });
    }
};
