<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_raw', 400);
            $table->decimal('qty_requested', 14, 2);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            // snapshot taken at review time
            $table->decimal('system_stock_snapshot', 14, 2)->nullable();
            $table->decimal('safety_stock_snapshot', 12, 2)->nullable();
            $table->decimal('projected_stock', 14, 2)->nullable();
            $table->boolean('below_safety_flag')->default(false);

            // physical verification by warehouse (NC-10)
            $table->string('physical_check_status', 20)->default('NOT_CHECKED'); // NOT_CHECKED/VERIFIED_MATCH/VERIFIED_MISMATCH
            $table->decimal('physical_check_qty', 14, 2)->nullable();
            $table->string('physical_check_note', 255)->nullable();
            $table->foreignId('physical_checked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('qty_approved', 14, 2)->nullable();
            $table->decimal('qty_reserved', 14, 2)->default(0);
            $table->decimal('qty_to_purchase', 14, 2)->default(0);
            $table->decimal('qty_issued', 14, 2)->default(0);
            // PENDING/READY/PARTIAL/NEED_PURCHASE/RESERVED/ISSUED/CANCELLED
            $table->string('line_status', 20)->default('PENDING');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_items');
    }
};
