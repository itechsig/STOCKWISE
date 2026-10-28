<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes Request Barang (Material Request) and Bukti Keluar Barang (Goods
 * Issue) entirely — both features stayed off behind a feature flag since
 * 2026-09-22/2026-09-29 and never accumulated real data (user decision
 * 2026-10-10: hapus dari sidebar, bukan hanya dimatikan).
 *
 * used_returns.npbg_id has the exact same stale FK every sibling tracking
 * table had (see 2026_09_19_035903 and 2026_09_29_*): it still pointed at
 * the renamed, empty `goods_issues` table instead of the real Accurate
 * `npbg` mirror, and was simply missed when those siblings got repointed.
 * No real used_returns row ever had this set (goods_issues was always
 * empty), so repointing it here — instead of just dropping the column — is
 * a safe, no-data-loss fix that brings it in line with every other
 * tracking table's out_npbg_id/npbg_id column.
 *
 * purchase_proposals/purchase_proposal_items (leftover from the earlier,
 * already-removed Purchase Proposal/Usulan Pembelian feature — both 0 rows,
 * no code references them) still hold two FKs into material_requests/
 * material_request_items. Only those two constraints are dropped here —
 * narrowly scoped to unblock this migration — not the tables themselves or
 * the separate stale ppb_id/ppb_item_id columns those dead tables' FKs leave
 * on the live purchase_orders/purchase_order_items/receivings tables; that
 * broader cleanup is a separate decision, out of scope for this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('used_returns', function (Blueprint $table) {
            $table->dropForeign(['npbg_id']);
            $table->foreign('npbg_id')->references('id')->on('npbg')->nullOnDelete();
        });

        Schema::table('purchase_proposal_items', function (Blueprint $table) {
            $table->dropForeign(['material_request_item_id']);
        });
        Schema::table('purchase_proposals', function (Blueprint $table) {
            $table->dropForeign(['source_request_id']);
        });

        Schema::dropIfExists('goods_issue_items');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('goods_issues');
        Schema::dropIfExists('material_request_items');
        Schema::dropIfExists('material_requests');
    }

    public function down(): void
    {
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->string('requester_name', 150)->nullable();
            $table->string('requester_wa', 30)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 255);
            $table->text('notes')->nullable();
            $table->date('request_date')->nullable();
            $table->string('work_location', 150)->nullable();
            $table->date('needed_date')->nullable();
            $table->string('status', 20)->default('DRAFT')->index();
            $table->string('npbg_no', 40)->nullable();
            $table->string('ppb_no', 40)->nullable();
            $table->string('request_ip', 45)->nullable();
            $table->string('network_label', 12)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('material_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_raw', 400);
            $table->decimal('qty_requested', 14, 2);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('system_stock_snapshot', 14, 2)->nullable();
            $table->decimal('safety_stock_snapshot', 12, 2)->nullable();
            $table->decimal('projected_stock', 14, 2)->nullable();
            $table->boolean('below_safety_flag')->default(false);
            $table->string('physical_check_status', 20)->default('NOT_CHECKED');
            $table->decimal('physical_check_qty', 14, 2)->nullable();
            $table->string('physical_check_note', 255)->nullable();
            $table->foreignId('physical_checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('qty_approved', 14, 2)->nullable();
            $table->decimal('qty_reserved', 14, 2)->default(0);
            $table->decimal('qty_to_purchase', 14, 2)->default(0);
            $table->decimal('qty_issued', 14, 2)->default(0);
            $table->string('line_status', 20)->default('PENDING');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('goods_issues', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('prefix', 12)->default('NA');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('sequence');
            $table->date('date');
            $table->string('type', 15)->default('NON_PENJUALAN');
            $table->string('classification', 25)->default('UMUM');
            $table->foreignId('material_request_id')->nullable()->constrained('material_requests')->nullOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('customer_name', 200)->nullable();
            $table->string('project_name', 200)->nullable();
            $table->string('asset_ref', 100)->nullable();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name', 150)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('DRAFT')->index();
            $table->string('signature_path')->nullable();
            $table->string('picked_up_by', 150)->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['prefix', 'year', 'month', 'sequence']);
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_request_id')->nullable()->constrained('material_requests')->nullOnDelete();
            $table->foreignId('material_request_item_id')->nullable()->constrained('material_request_items')->nullOnDelete();
            $table->decimal('qty', 14, 2);
            $table->string('status', 15)->default('ACTIVE');
            $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'warehouse_id', 'status']);
        });

        Schema::create('goods_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npbg_id')->constrained('goods_issues')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_request_item_id')->nullable()->constrained('material_request_items')->nullOnDelete();
            $table->foreignId('stock_reservation_id')->nullable()->constrained('stock_reservations')->nullOnDelete();
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

        Schema::table('used_returns', function (Blueprint $table) {
            $table->dropForeign(['npbg_id']);
            $table->foreign('npbg_id')->references('id')->on('goods_issues')->nullOnDelete();
        });

        Schema::table('purchase_proposals', function (Blueprint $table) {
            $table->foreign('source_request_id')->references('id')->on('material_requests')->nullOnDelete();
        });
        Schema::table('purchase_proposal_items', function (Blueprint $table) {
            $table->foreign('material_request_item_id')->references('id')->on('material_request_items')->nullOnDelete();
        });
    }
};
