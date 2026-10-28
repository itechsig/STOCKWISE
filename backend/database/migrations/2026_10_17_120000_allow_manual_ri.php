<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow manually-created RI rows ("Tambah RI") to coexist with Accurate-synced
 * ones — same convention as the NPBG/PPB migrations: accurate_apinvoice_id/
 * accurate_seq become nullable (NULL,NULL pairs in the unique index are distinct
 * in MySQL), "is this row from Accurate?" is "is accurate_apinvoice_id null?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ri', function (Blueprint $table) {
            $table->unsignedInteger('accurate_apinvoice_id')->nullable()->change();
            $table->unsignedInteger('accurate_seq')->nullable()->change();
            $table->foreignId('created_by')->nullable()->after('pemeriksa')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ri', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->unsignedInteger('accurate_apinvoice_id')->nullable(false)->change();
            $table->unsignedInteger('accurate_seq')->nullable(false)->change();
        });
    }
};
