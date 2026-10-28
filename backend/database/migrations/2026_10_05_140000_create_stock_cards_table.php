<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kartu Stock: the header fields of an item's stock card that Accurate does not
 * hold (card number, location, person in charge, director). The movements
 * themselves come from Accurate's ITEMHIST (App\Services\Inventory\StockCardService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('card_number', 60)->nullable()->index();
            $t->string('location_warehouse', 60)->nullable();
            $t->string('location_rack', 60)->nullable();
            $t->string('person_in_charge', 150)->nullable();
            $t->string('director_name', 150)->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_cards');
    }
};
