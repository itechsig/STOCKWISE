<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengembalian Bekas = the Excel "8. Tracking Pengembalian Bekas": its two
 * tracking sheets, row for row. Kept apart from used_returns (the RI/NV documents
 * STOCKWISE derives from Accurate, with their own close-into-stock flow) so the
 * Excel tracking never moves stock by itself.
 *   used_return_matrix_rows  sheet "Spare Part"      — NPBG out → RI back, 14 component columns (signed)
 *   used_return_other_rows   sheet "Spare Part Lain" — one item per row; Foto Keluar / Terima = tracking_photos
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('used_return_matrix_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sheet_no')->nullable()->index();
            $table->date('npbg_date')->nullable();
            $table->string('npbg_no', 40)->nullable()->index();
            $table->foreignId('npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->string('status', 12)->default('PENDING')->index(); // CLEAR / PENDING / INCOMPLETE
            $table->date('ri_date')->nullable();
            $table->string('ri_no', 40)->nullable()->index();
            $table->json('components')->nullable(); // {"BONIT_BR": 2, "MUR_CS": -4, …} — surplus (+) / shortage (−)
            $table->text('note')->nullable();
            $table->string('source', 10)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('used_return_other_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sheet_no')->nullable()->index();
            $table->date('npbg_date')->nullable();
            $table->string('npbg_no', 40)->nullable()->index();
            $table->foreignId('npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->string('description', 400)->nullable();
            $table->decimal('qty', 12, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->unsignedInteger('item_no')->nullable();       // "Item No"
            $table->string('out_file_name', 255)->nullable();     // "Nama File (#.ext)" (Foto Keluar)
            $table->string('status', 12)->default('PENDING')->index();
            $table->string('ri_no', 40)->nullable()->index();
            $table->unsignedInteger('return_item_no')->nullable(); // "Item No2"
            $table->string('in_file_name', 255)->nullable();      // "Nama File (#.ext2)" (Foto Terima)
            $table->text('note')->nullable();
            $table->string('source', 10)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_return_other_rows');
        Schema::dropIfExists('used_return_matrix_rows');
    }
};
