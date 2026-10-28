<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ban Luar = the Excel "5. Tracking Ban Luar": the columns of sheet "Ban Luar"
 * STOCKWISE missed, and its two other sheets ("Ban Luar BPN", "Deliver & Receive
 * Ban SIG-BPN"). Foto Ban (Out/In) are tracking_photos. The NPBG link no longer
 * deletes a tyre row when the NPBG line disappears (data typed from the Excel
 * must survive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tyre_changes', function (Blueprint $table) {
            $table->dropForeign(['out_npbg_id']);
            $table->foreign('out_npbg_id')->references('id')->on('npbg')->nullOnDelete();
            $table->unsignedInteger('sheet_no')->nullable()->after('id')->index();      // "No"
            $table->string('out_npbg_no', 40)->nullable()->after('out_npbg_id')->index(); // "No NPBG" as typed (ORIGIN = opening data)
            $table->string('new_file_name', 255)->nullable()->after('new_serial_raw');  // "Column1" = Nama File (Foto Ban Out)
            $table->unsignedSmallInteger('tyre_no')->nullable()->after('position');     // "Ban"
            $table->unsignedSmallInteger('replacement_no')->nullable()->after('tyre_no'); // "Pergantian"
            $table->string('in_ri_no', 40)->nullable()->after('in_ri_id');              // "No. RI" as typed
            $table->string('old_file_name', 255)->nullable()->after('old_serial_raw');  // "Nama File (#.ext2)" (Foto Ban In)
        });

        // sheet "Ban Luar BPN" — master tyres of the SIG-BPN fleet at the cut-off
        Schema::create('tyre_bpn_inventory', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sheet_no')->nullable()->index();
            $table->date('cutoff_date')->nullable();
            $table->string('nopol_raw', 120)->nullable()->index();
            $table->string('tyre_desc', 300)->nullable();
            $table->string('serial_raw', 80)->nullable()->index();
            $table->text('note')->nullable();
            $table->string('source', 10)->nullable();
            $table->timestamps();
        });

        // sheet "Deliver & Receive Ban SIG-BPN" — tyres sent to BPN and the used ones coming back
        Schema::create('tyre_bpn_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sheet_no')->nullable()->index();
            $table->string('nopol_raw', 120)->nullable()->index();
            $table->date('npbg_date')->nullable();
            $table->string('npbg_no', 40)->nullable();
            $table->foreignId('npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->string('new_tyre_desc', 300)->nullable();
            $table->string('new_serial_raw', 80)->nullable();
            $table->text('out_note')->nullable();
            $table->date('ri_date')->nullable();
            $table->string('ri_no', 40)->nullable();
            $table->string('old_tyre_desc', 300)->nullable();
            $table->string('old_serial_raw', 80)->nullable();
            $table->text('in_note')->nullable();
            $table->string('source', 10)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tyre_bpn_transfers');
        Schema::dropIfExists('tyre_bpn_inventory');
        Schema::table('tyre_changes', function (Blueprint $table) {
            $table->dropIndex(['sheet_no']);
            $table->dropIndex(['out_npbg_no']);
            $table->dropColumn(['sheet_no', 'out_npbg_no', 'new_file_name', 'tyre_no', 'replacement_no', 'in_ri_no', 'old_file_name']);
            $table->dropForeign(['out_npbg_id']);
            $table->foreign('out_npbg_id')->references('id')->on('npbg')->cascadeOnDelete();
        });
    }
};
