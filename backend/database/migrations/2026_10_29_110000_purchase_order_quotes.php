<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft PO: the price purchasing got from each vendor for an item still waiting for its PO
 * (a "Menunggu PO" line) — the comparison to pick the vendor of the PO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained();
            $table->decimal('unit_price', 18, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['purchase_order_item_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_quotes');
    }
};
