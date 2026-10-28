<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('npbg_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npbg_id')->constrained('npbg')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_request_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_raw', 400);
            $table->unsignedInteger('item_no')->nullable();
            $table->decimal('qty', 14, 2);
            $table->decimal('qty_issued', 14, 2)->default(0);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('npbg_items');
    }
};
