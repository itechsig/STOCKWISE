<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borrow & Lend = the Excel "3. Tracking Borrow & Lend": the columns STOCKWISE
 * missed. "Tanda Keluar / Kembali / Terima" hold a document number or a picture
 * (the picture = tracking_photos slot out / in). The Accurate link no longer
 * deletes a loan when its source line disappears (rows from the Excel must survive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lend_transactions', function (Blueprint $table) {
            $table->dropForeign(['out_npbg_id']);
            $table->foreign('out_npbg_id')->references('id')->on('npbg')->nullOnDelete();
            $table->unsignedInteger('sheet_no')->nullable()->after('id')->index(); // "No"
            $table->string('unit_raw', 20)->nullable()->after('unit_id');          // "Satuan"
            $table->string('out_doc_no', 60)->nullable()->after('out_npbg_id');   // "Tanda Keluar" (NPBG no.)
            $table->string('return_doc_no', 200)->nullable()->after('return_ri_id'); // "Tanda Kembali" (RI no.)
        });
        Schema::table('borrow_transactions', function (Blueprint $table) {
            $table->dropForeign(['in_ri_id']);
            $table->foreign('in_ri_id')->references('id')->on('ri')->nullOnDelete();
            $table->unsignedInteger('sheet_no')->nullable()->after('id')->index(); // "No"
            $table->string('unit_raw', 20)->nullable()->after('unit_id');          // "Satuan"
            $table->string('in_doc_no', 60)->nullable()->after('in_ri_id');       // "Tanda Terima" (RI no.)
            $table->string('out_doc_no', 200)->nullable()->after('return_npbg_id'); // "Tanda Keluar" (NPBG no.)
        });
    }

    public function down(): void
    {
        Schema::table('borrow_transactions', function (Blueprint $table) {
            $table->dropIndex(['sheet_no']);
            $table->dropColumn(['sheet_no', 'unit_raw', 'in_doc_no', 'out_doc_no']);
            $table->dropForeign(['in_ri_id']);
            $table->foreign('in_ri_id')->references('id')->on('ri')->cascadeOnDelete();
        });
        Schema::table('lend_transactions', function (Blueprint $table) {
            $table->dropIndex(['sheet_no']);
            $table->dropColumn(['sheet_no', 'unit_raw', 'out_doc_no', 'return_doc_no']);
            $table->dropForeign(['out_npbg_id']);
            $table->foreign('out_npbg_id')->references('id')->on('npbg')->cascadeOnDelete();
        });
    }
};
