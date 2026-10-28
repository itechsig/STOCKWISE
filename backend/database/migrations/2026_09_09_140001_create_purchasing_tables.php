<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('code', 30)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->string('source', 40)->nullable();
            $table->timestamps();
            $table->unique('name');
        });

        Schema::create('ppb', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('prefix', 12)->default('NA');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('sequence');
            $table->date('date');
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_request_id')->nullable()->constrained('material_requests')->nullOnDelete();
            // DRAFT/SUBMITTED/REVIEW/APPROVED/PURCHASING/ORDERED/PARTIAL_RECEIVED/RECEIVED/COMPLETED/CANCELLED
            $table->string('status', 20)->default('DRAFT')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['prefix', 'year', 'month', 'sequence']);
        });

        Schema::create('ppb_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ppb_id')->constrained('ppb')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_request_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_raw', 400);
            $table->decimal('qty', 14, 2);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('shortage_qty', 14, 2)->nullable();
            $table->decimal('safety_stock_snapshot', 12, 2)->nullable();
            $table->decimal('deficit_snapshot', 12, 2)->nullable();
            $table->decimal('priority_score_snapshot', 12, 2)->nullable();
            $table->string('priority_level_snapshot', 8)->nullable();
            $table->decimal('qty_ordered', 14, 2)->default(0);
            $table->decimal('qty_received', 14, 2)->default(0);
            $table->string('line_status', 20)->default('PENDING');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('ppb_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ppb_id')->constrained('ppb')->cascadeOnDelete();
            $table->foreignId('ppb_item_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('type', 10); // AMEND / CLOSE
            $table->decimal('qty_before', 14, 2)->nullable();
            $table->decimal('qty_after', 14, 2)->nullable();
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('prefix', 12)->default('BL');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('sequence');
            $table->date('date');
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ppb_id')->nullable()->constrained('ppb')->nullOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('expected_date')->nullable();
            // DRAFT/APPROVED/SENT/PARTIAL_RECEIVED/RECEIVED/CLOSED/CANCELLED
            $table->string('status', 20)->default('DRAFT')->index();
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['prefix', 'year', 'month', 'sequence']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ppb_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_raw', 400);
            $table->decimal('qty', 14, 2);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->decimal('line_total', 16, 2)->default(0);
            $table->decimal('qty_received', 14, 2)->default(0);
            $table->string('line_status', 20)->default('PENDING');
            $table->timestamps();
        });

        Schema::create('receivings', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('prefix', 12)->default('NV');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('sequence');
            $table->date('date');
            // PURCHASE / LEND_RETURN / BORROW_RETURN / STPP_RETURN / TYRE_OLD / MANUFACTURING_OUTPUT / USED_RETURN / TRANSFER_IN / OPENING
            $table->string('source_type', 24)->default('PURCHASE');
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ppb_id')->nullable()->constrained('ppb')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('surat_jalan_no', 60)->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // DRAFT / CHECKING / CONFIRMED / PARTIAL / REJECTED / CANCELLED
            $table->string('status', 15)->default('DRAFT')->index();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['prefix', 'year', 'month', 'sequence']);
        });

        Schema::create('receiving_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receiving_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_raw', 400);
            $table->decimal('qty_expected', 14, 2)->nullable();
            $table->decimal('qty_received', 14, 2);
            $table->decimal('qty_accepted', 14, 2);
            $table->decimal('qty_rejected', 14, 2)->default(0);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('condition_note', 255)->nullable();
            $table->boolean('into_stock')->default(true);
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_items');
        Schema::dropIfExists('receivings');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('ppb_amendments');
        Schema::dropIfExists('ppb_items');
        Schema::dropIfExists('ppb');
        Schema::dropIfExists('vendors');
    }
};
