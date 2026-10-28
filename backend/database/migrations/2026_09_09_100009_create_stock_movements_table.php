<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('movement_type', 24);   // OPENING_BALANCE/STOCK_IN/STOCK_OUT/RECEIVING/RETURN/
            // STOCK_ADJUSTMENT/RESERVATION/RELEASE_RESERVATION/TRANSFER_IN/TRANSFER_OUT
            $table->tinyInteger('direction')->default(0);      // +1 / -1 / 0 (reservation-only)
            $table->decimal('qty', 14, 2);                     // always positive
            $table->decimal('actual_before', 14, 2);
            $table->decimal('actual_after', 14, 2);
            $table->decimal('reserved_before', 14, 2);
            $table->decimal('reserved_after', 14, 2);
            $table->nullableMorphs('reference');               // npbg_item, receiving_item, stock_adjustment, ...
            $table->uuid('batch_uuid')->nullable()->index();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_id', 'warehouse_id', 'created_at']);
            $table->index('movement_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
