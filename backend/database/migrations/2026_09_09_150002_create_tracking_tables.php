<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lend_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_raw', 400);
            $table->decimal('qty', 14, 2);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 12)->default('RELASI'); // INTERNAL / PROJECT / RELASI
            $table->string('borrower_name', 200)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('est_days')->nullable();
            $table->foreignId('out_npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->foreignId('return_ri_id')->nullable()->constrained('receivings')->nullOnDelete();
            $table->date('out_date');
            $table->date('due_date')->nullable();
            $table->date('return_date')->nullable();
            $table->decimal('qty_returned', 14, 2)->default(0);
            $table->string('status', 20)->default('ON_LOAN')->index(); // ON_LOAN/PARTIAL_RETURN/RETURNED/OVERDUE
            $table->string('condition_out', 255)->nullable();
            $table->string('condition_in', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('borrow_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_raw', 400);
            $table->decimal('qty', 14, 2);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lender_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('lender_name', 200)->nullable();
            $table->string('receipt_ref', 60)->nullable();
            $table->foreignId('return_npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->date('borrowed_at');
            $table->date('returned_at')->nullable();
            $table->decimal('qty_returned', 14, 2)->default(0);
            $table->string('status', 15)->default('BORROWED')->index(); // BORROWED/PARTIAL/RETURNED
            $table->string('condition_note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stpp_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('serial_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_no_raw', 80)->nullable();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_raw', 400);
            $table->decimal('qty', 14, 2)->default(1);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('holder_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('holder_name_raw', 150)->nullable();
            $table->foreignId('placement_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('placement_raw', 80)->nullable();
            $table->foreignId('out_npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->date('out_date');
            $table->string('status', 10)->default('ACTIVE')->index(); // ACTIVE / PASSIVE
            $table->foreignId('return_ri_id')->nullable()->constrained('receivings')->nullOnDelete();
            $table->date('return_date')->nullable();
            $table->string('out_note', 255)->nullable();
            $table->string('return_note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tyre_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('change_date');
            $table->string('position', 20)->nullable();
            $table->unsignedSmallInteger('change_seq')->nullable();
            $table->foreignId('out_npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->foreignId('new_serial_unit_id')->nullable()->constrained('serial_units')->nullOnDelete();
            $table->string('new_tyre_desc', 300)->nullable();
            $table->string('new_serial_raw', 80)->nullable();
            $table->string('status', 12)->default('PENDING_RI')->index(); // PENDING_RI / CLEAR
            $table->foreignId('in_ri_id')->nullable()->constrained('receivings')->nullOnDelete();
            $table->date('in_date')->nullable();
            $table->foreignId('old_serial_unit_id')->nullable()->constrained('serial_units')->nullOnDelete();
            $table->string('old_tyre_desc', 300)->nullable();
            $table->string('old_serial_raw', 80)->nullable();
            $table->string('reason', 255)->nullable();
            $table->boolean('is_opening')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('maintenance_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique(); // No SPK
            $table->date('report_date');
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('problem_summary', 255)->nullable();
            $table->string('status', 12)->default('OPEN')->index(); // OPEN/ON_GOING/COMPLETED/CANCELLED
            $table->date('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('maintenance_order_subs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_order_id')->constrained()->cascadeOnDelete();
            $table->string('sub_no', 10);
            $table->foreignId('workshop_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workshop_raw', 80)->nullable();
            $table->text('problem_detail')->nullable();
            $table->foreignId('npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->foreignId('ri_id')->nullable()->constrained('receivings')->nullOnDelete();
            $table->string('status', 12)->default('ON_GOING'); // ON_GOING / COMPLETED
            $table->date('finish_date')->nullable();
            $table->text('result_note')->nullable();
            $table->timestamps();
            $table->unique(['maintenance_order_id', 'sub_no']);
        });

        Schema::create('manufacturing_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique(); // No MA / No MJ
            $table->string('kind', 10)->default('ASSEMBLY'); // ASSEMBLY / JASA
            $table->date('date');
            $table->string('product_name', 200)->nullable();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('REQUESTED')->index(); // REQUESTED/ON_GOING/COMPLETED/CANCELLED
            $table->date('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('manufacturing_order_subs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();
            $table->string('sub_no', 10);
            $table->unsignedInteger('item_no')->nullable();
            $table->foreignId('serial_unit_id')->nullable()->constrained('serial_units')->nullOnDelete();
            $table->string('serial_no_raw', 80)->nullable();
            $table->string('process', 60)->nullable();
            $table->foreignId('npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->foreignId('ri_id')->nullable()->constrained('receivings')->nullOnDelete();
            $table->string('status', 12)->default('ON_GOING'); // ON_GOING / COMPLETED
            $table->date('finish_date')->nullable();
            $table->text('note_start')->nullable();
            $table->text('note_end')->nullable();
            $table->timestamps();
            $table->unique(['manufacturing_order_id', 'sub_no']);
        });

        Schema::create('used_return_component_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 80);
            $table->foreignId('default_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('used_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->string('npbg_ref_raw', 60)->nullable();
            $table->foreignId('ri_id')->nullable()->constrained('receivings')->nullOnDelete();
            $table->date('return_date')->nullable();
            $table->string('status', 10)->default('PENDING')->index(); // PENDING / CLEAR
            $table->string('format', 18)->default('ITEM_LINE'); // COMPONENT_MATRIX / ITEM_LINE
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('used_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('used_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('component_type_id')->nullable()->constrained('used_return_component_types')->nullOnDelete();
            $table->string('description_raw', 400)->nullable();
            $table->decimal('qty', 12, 2); // signed — negatif = shortage
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('condition', 12)->default('USED'); // REUSABLE/SCRAP/DAMAGED/USED
            $table->boolean('into_stock')->default(false);
            $table->unsignedInteger('item_no')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_return_items');
        Schema::dropIfExists('used_returns');
        Schema::dropIfExists('used_return_component_types');
        Schema::dropIfExists('manufacturing_order_subs');
        Schema::dropIfExists('manufacturing_orders');
        Schema::dropIfExists('maintenance_order_subs');
        Schema::dropIfExists('maintenance_orders');
        Schema::dropIfExists('tyre_changes');
        Schema::dropIfExists('stpp_transactions');
        Schema::dropIfExists('borrow_transactions');
        Schema::dropIfExists('lend_transactions');
    }
};
