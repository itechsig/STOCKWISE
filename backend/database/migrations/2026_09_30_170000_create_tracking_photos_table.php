<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence photos of the tracking modules — what the Excel keeps in the
 * "DATA BUKTI" folder (Foto Sebelum / Sesudah, Bukti Keluar / Terima, Foto Ban …)
 * and as pictures inside cells (Permintaan, Tanda Terima). One photo per
 * row + slot, stored in STOCKWISE so the folder is no longer needed.
 *
 * Also the Excel columns Maintenance Assets still missed per Sub SPK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_photos', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 30);            // maintenance_sub, stpp, tyre, used_return_line, lend, borrow …
            $table->unsignedBigInteger('entity_id');
            $table->string('slot', 20);              // before / after / request / out / in …
            $table->string('file_name', 255)->nullable(); // Excel "Nama File" (without extension)
            $table->string('path', 400);
            $table->string('mime', 60)->nullable();
            $table->string('source', 10)->nullable(); // EXCEL / UPLOAD
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['entity', 'entity_id', 'slot']);
        });

        Schema::table('maintenance_order_subs', function (Blueprint $table) {
            $table->unsignedInteger('sheet_no')->nullable()->after('id')->index();
            $table->string('nopol_raw', 120)->nullable()->after('report_date');       // Excel "Nopol (Kendaraan)" per row
            $table->string('before_file_name', 255)->nullable()->after('request_note'); // "Nama File" + "(#.ext)"
            $table->string('after_file_name', 255)->nullable()->after('npbg_no');     // "Nama File 2" + "(#.ext2)"
            $table->string('source', 10)->nullable()->after('result_note');
            // the Excel has the same Sub SPK twice in a few SPK (5×) — a group, not a key
            $table->index(['maintenance_order_id', 'sub_no'], 'mk_subs_order_sub_idx');
        });
        Schema::table('maintenance_order_subs', function (Blueprint $table) {
            $table->dropUnique(['maintenance_order_id', 'sub_no']);
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_order_subs', function (Blueprint $table) {
            $table->unique(['maintenance_order_id', 'sub_no']);
        });
        Schema::table('maintenance_order_subs', function (Blueprint $table) {
            $table->dropIndex('mk_subs_order_sub_idx');
            $table->dropIndex(['sheet_no']);
            $table->dropColumn(['sheet_no', 'nopol_raw', 'before_file_name', 'after_file_name', 'source']);
        });
        Schema::dropIfExists('tracking_photos');
    }
};
