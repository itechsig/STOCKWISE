<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->decimal('actual_qty', 14, 2)->default(0);
            $table->decimal('reserved_qty', 14, 2)->default(0);
            $table->decimal('available_qty', 14, 2)->storedAs('actual_qty - reserved_qty');
            $table->boolean('stock_known')->default(false);       // false = "UNKNOWN" per NC-4 / D2
            $table->timestamp('last_counted_at')->nullable();
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id']);
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
