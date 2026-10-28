<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Master Produk: an item is a BARANG (goods, has stock) or a JASA (service, no stock).
 *    Every existing item is BARANG; Accurate items of ITEMTYPE 2 (service) come in as JASA.
 * 2. Draft PPB: the Restock button of Safety Stock makes a draft the admin reviews and edits
 *    before it becomes a real (manual) PPB — App\Services\PpbDraftService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('product_kind', 10)->default('BARANG')->after('item_type')->index();
        });

        Schema::create('ppb_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            // DRAFT (still editable) / DIAJUKAN (became PPB no_ppb) / DIBATALKAN
            $table->string('status', 12)->default('DRAFT')->index();
            $table->string('source', 20)->default('RESTOCK');
            $table->string('divisi', 30)->nullable();
            $table->date('tgl_diperlukan')->nullable();
            $table->string('peminta', 150)->nullable();
            $table->text('keterangan')->nullable();
            $table->string('no_ppb', 60)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ppb_draft_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ppb_draft_id')->constrained('ppb_drafts')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('kode_barang', 60)->nullable();
            $table->string('deskripsi_barang', 400);
            $table->decimal('qty', 14, 2);
            $table->string('satuan', 30)->nullable();
            $table->string('catatan', 255)->nullable();
            // why it was suggested, e.g. "Tersedia 3 · Safety stock 10 · Saran 12"
            $table->string('basis', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppb_draft_lines');
        Schema::dropIfExists('ppb_drafts');
        Schema::table('items', fn (Blueprint $table) => $table->dropColumn('product_kind'));
    }
};
