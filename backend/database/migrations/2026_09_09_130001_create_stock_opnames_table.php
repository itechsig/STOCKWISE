<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->string('type', 12)->default('PARTIAL');   // FULL / PARTIAL / OPENING
            // DRAFT/SCHEDULED/IN_PROGRESS/SUBMITTED/PENDING_REVIEW/APPROVED/REJECTED/RECOUNT_REQUIRED/COMPLETED
            $table->string('status', 20)->default('SCHEDULED')->index();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('system_qty', 14, 2)->default(0);
            $table->decimal('physical_qty', 14, 2)->nullable();
            $table->decimal('difference', 14, 2)->storedAs('physical_qty - system_qty');
            $table->string('note', 255)->nullable();
            $table->string('count_status', 10)->default('PENDING');   // PENDING / COUNTED
            $table->string('review_status', 12)->default('PENDING');  // PENDING / APPROVED / REJECTED / RECOUNT
            $table->timestamps();

            $table->unique(['stock_opname_id', 'item_id']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty_before', 14, 2);
            $table->decimal('qty_after', 14, 2);
            $table->decimal('difference', 14, 2);
            $table->string('reason', 255);
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
    }
};
