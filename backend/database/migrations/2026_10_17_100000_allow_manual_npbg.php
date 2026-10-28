<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow manually-created NPBG rows ("Tambah NPBG") to coexist with Accurate-synced
 * ones — accurate_arinvoice_id/accurate_seq become nullable (MySQL treats NULL,NULL
 * pairs in a unique index as distinct, so many manual rows can coexist under the
 * same unique constraint). "Is this row from Accurate?" is then just "is
 * accurate_arinvoice_id null?" — same convention as stock_opnames.accurate_itemadj_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('npbg', function (Blueprint $table) {
            $table->unsignedInteger('accurate_arinvoice_id')->nullable()->change();
            $table->unsignedInteger('accurate_seq')->nullable()->change();
            $table->string('alamat_kirim', 255)->nullable()->after('pelanggan');
            $table->foreignId('created_by')->nullable()->after('alamat_kirim')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('npbg', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('alamat_kirim');
            $table->unsignedInteger('accurate_arinvoice_id')->nullable(false)->change();
            $table->unsignedInteger('accurate_seq')->nullable(false)->change();
        });
    }
};
