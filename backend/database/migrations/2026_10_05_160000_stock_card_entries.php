<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kartu Stock as the paper card ("KARTU STOCK BARANG", e.g. the CCTV installation
 * project): created per item from Master Barang, its lines written by hand —
 * Tgl, No. Bukti, Keterangan, Masuk, Keluar (Sisa is computed). stock_cards was
 * still empty: the card number becomes unique and an item may have more cards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_cards', function (Blueprint $t) {
            $t->index('item_id', 'stock_cards_item_id_index'); // the FK keeps an index once the unique one is gone
        });
        Schema::table('stock_cards', function (Blueprint $t) {
            $t->dropUnique(['item_id']);
            $t->dropIndex(['card_number']);
            $t->unique('card_number');
            $t->foreignId('created_by')->nullable()->after('director_name')->constrained('users')->nullOnDelete();
        });

        Schema::create('stock_card_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_card_id')->constrained()->cascadeOnDelete();
            $t->date('entry_date');
            $t->string('doc_no', 80)->nullable();
            $t->string('description', 500)->nullable();
            $t->decimal('qty_in', 18, 4)->default(0);
            $t->decimal('qty_out', 18, 4)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['stock_card_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_card_entries');
        Schema::table('stock_cards', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
            $t->dropUnique(['card_number']);
            $t->index('card_number');
            $t->unique('item_id');
        });
        Schema::table('stock_cards', fn (Blueprint $t) => $t->dropIndex('stock_cards_item_id_index'));
    }
};
