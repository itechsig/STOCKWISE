<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STPP = the Excel "4. Tracking STPP": the columns it has per unit that STOCKWISE
 * missed, and its second sheet "Maintenance" (refill / repair log per No. Seri).
 * Bukti Keluar / Bukti Terima are tracking_photos (entity "stpp", slots out / in).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stpp_transactions', function (Blueprint $table) {
            $table->unsignedInteger('sheet_no')->nullable()->after('id')->index(); // "No"
            $table->string('unit_raw', 20)->nullable()->after('unit_id');          // "Satuan" as typed
            $table->string('out_npbg_no', 40)->nullable()->after('out_npbg_id')->index(); // "No. NPBG" as typed
            $table->unsignedInteger('out_item_no')->nullable()->after('out_npbg_no');     // "Item No"
            $table->string('out_file_name', 255)->nullable()->after('out_item_no');       // "Nama File" (Bukti Keluar)
            $table->string('return_ri_no', 40)->nullable()->after('return_ri_id');        // "Tanda Kembali" (No. RI)
            $table->unsignedInteger('return_item_no')->nullable()->after('return_ri_no'); // "Item No2"
            $table->string('return_file_name', 255)->nullable()->after('return_item_no'); // "Nama File 2" (Bukti Terima)
            $table->string('source', 10)->nullable()->after('return_note');
        });

        Schema::create('stpp_maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sheet_no')->nullable()->index();
            $table->string('serial_no_raw', 80)->index();      // "No Seri" (looks up the STPP unit)
            $table->string('requester', 150)->nullable();      // "Peminta"
            $table->date('npbg_date')->nullable();             // "Tgl NPBG"
            $table->string('npbg_no', 40)->nullable();         // "No NPBG"
            $table->foreignId('npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->text('note')->nullable();                  // "Keterangan"
            $table->string('source', 10)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stpp_maintenance_logs');
        Schema::table('stpp_transactions', function (Blueprint $table) {
            $table->dropIndex(['sheet_no']);
            $table->dropIndex(['out_npbg_no']);
            $table->dropColumn(['sheet_no', 'unit_raw', 'out_npbg_no', 'out_item_no', 'out_file_name', 'return_ri_no', 'return_item_no', 'return_file_name', 'source']);
        });
    }
};
