<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_request_item_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('qty', 14, 2);
            $table->string('status', 15)->default('ACTIVE'); // ACTIVE / RELEASED / CONSUMED / EXPIRED
            $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
