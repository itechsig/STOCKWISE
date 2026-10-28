<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The remaining Excel "7. Tracking Manufaktur & Assembly" columns per row:
 *   sheet_no   "No" (row number in the sheet)
 *   row_date   "Tanggal" and row_location "Lokasi" — per row in the Excel (fallback: the MA/MJ header)
 *   file_name  "Nama File (#.ext)" as entered; with it the Excel shows the Foto Hasil link and counts the
 *              row as COMPLETED (together with tgl selesai + No. RI)
 *   item_id    Hasil Produk linked to the Accurate item of the same description
 *   source     EXCEL for rows imported from the workbook
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manufacturing_order_subs', function (Blueprint $table) {
            $table->unsignedInteger('sheet_no')->nullable()->after('id')->index();
            $table->date('row_date')->nullable()->after('sub_no');
            $table->string('row_location', 150)->nullable()->after('row_date');
            $table->foreignId('item_id')->nullable()->after('product_name')->constrained('items')->nullOnDelete();
            $table->string('file_name', 255)->nullable()->after('ri_no');
            $table->string('source', 10)->nullable()->after('note_end');
        });
    }

    public function down(): void
    {
        Schema::table('manufacturing_order_subs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_id');
            $table->dropIndex(['sheet_no']);
            $table->dropColumn(['sheet_no', 'row_date', 'row_location', 'file_name', 'source']);
        });
    }
};
