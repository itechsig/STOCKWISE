<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking modules aligned with the reference Excel files (Borrow & Lend,
 * Ban Luar, Maintenance Assets, Manufaktur & Assembly — used as a FORMAT
 * reference only, never as a data source; user decision 2026-09-29).
 *
 * - NPBG / RI links repointed from the renamed, empty goods_issues /
 *   receivings tables to the real Accurate mirrors `npbg` / `ri` (same stale
 *   FK as STPP had, see 2026_09_19_035903). All these tables were empty.
 * - Vehicles are typed as the NOPOL exactly as it appears in Accurate
 *   ("W 8985 QF (HINO DUTRO)"), so asset_id becomes optional + nopol_raw.
 * - Columns the Excel has and the tables lacked (Peminta, Keterangan Kembali,
 *   Lokasi, Permintaan, NPBG/RI numbers as typed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lend_transactions', function (Blueprint $table) {
            $table->string('requester_name', 100)->nullable()->after('borrower_name'); // Excel "Peminta"
            $table->string('return_note', 500)->nullable()->after('return_refs');       // Excel "Keterangan Kembali"
        });
        Schema::table('borrow_transactions', function (Blueprint $table) {
            $table->string('return_note', 500)->nullable()->after('return_refs');       // Excel "Keterangan Barang Kembali"
        });

        Schema::table('tyre_changes', function (Blueprint $table) {
            $table->dropForeign(['out_npbg_id']);
            $table->dropForeign(['in_ri_id']);
            $table->foreign('out_npbg_id')->references('id')->on('npbg')->cascadeOnDelete();
            $table->foreign('in_ri_id')->references('id')->on('ri')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->change();
            $table->string('nopol_raw', 120)->nullable()->after('asset_id')->index();
            $table->string('source', 10)->default('MANUAL')->after('status')->index();
            $table->string('out_note', 500)->nullable()->after('reason');   // Excel "Keterangan Keluar"
            $table->string('in_note', 500)->nullable()->after('out_note');  // Excel "Keterangan Kembali"
            $table->unique(['out_npbg_id', 'change_seq']);
        });

        Schema::table('maintenance_orders', function (Blueprint $table) {
            $table->foreignId('asset_id')->nullable()->change();
            $table->string('nopol_raw', 120)->nullable()->after('asset_id')->index();
        });
        Schema::table('maintenance_order_subs', function (Blueprint $table) {
            $table->dropForeign(['npbg_id']);
            $table->dropForeign(['ri_id']);
            $table->foreign('npbg_id')->references('id')->on('npbg')->nullOnDelete();
            $table->foreign('ri_id')->references('id')->on('ri')->nullOnDelete();
            $table->date('report_date')->nullable()->after('sub_no');           // Excel "Tgl Laporan" per sub
            $table->string('request_note', 500)->nullable()->after('problem_detail'); // Excel "Permintaan"
            $table->string('npbg_no', 40)->nullable()->after('npbg_id');        // as typed; resolved to npbg_id
        });

        Schema::table('manufacturing_orders', function (Blueprint $table) {
            $table->string('location', 150)->nullable()->after('product_name'); // Excel "Lokasi"
        });
        Schema::table('manufacturing_order_subs', function (Blueprint $table) {
            $table->dropForeign(['npbg_id']);
            $table->dropForeign(['ri_id']);
            $table->foreign('npbg_id')->references('id')->on('npbg')->nullOnDelete();
            $table->foreign('ri_id')->references('id')->on('ri')->nullOnDelete();
            $table->string('npbg_no', 40)->nullable()->after('npbg_id');
            $table->string('ri_no', 40)->nullable()->after('ri_id');
        });
    }

    public function down(): void
    {
        Schema::table('manufacturing_order_subs', function (Blueprint $table) {
            $table->dropColumn(['npbg_no', 'ri_no']);
            $table->dropForeign(['npbg_id']);
            $table->dropForeign(['ri_id']);
            $table->foreign('npbg_id')->references('id')->on('goods_issues')->nullOnDelete();
            $table->foreign('ri_id')->references('id')->on('receivings')->nullOnDelete();
        });
        Schema::table('manufacturing_orders', fn (Blueprint $table) => $table->dropColumn('location'));
        Schema::table('maintenance_order_subs', function (Blueprint $table) {
            $table->dropColumn(['report_date', 'request_note', 'npbg_no']);
            $table->dropForeign(['npbg_id']);
            $table->dropForeign(['ri_id']);
            $table->foreign('npbg_id')->references('id')->on('goods_issues')->nullOnDelete();
            $table->foreign('ri_id')->references('id')->on('receivings')->nullOnDelete();
        });
        Schema::table('maintenance_orders', fn (Blueprint $table) => $table->dropColumn('nopol_raw'));
        Schema::table('tyre_changes', function (Blueprint $table) {
            $table->dropUnique(['out_npbg_id', 'change_seq']);
            $table->dropColumn(['nopol_raw', 'source', 'out_note', 'in_note']);
            $table->dropForeign(['out_npbg_id']);
            $table->dropForeign(['in_ri_id']);
            $table->foreign('out_npbg_id')->references('id')->on('goods_issues')->nullOnDelete();
            $table->foreign('in_ri_id')->references('id')->on('receivings')->nullOnDelete();
        });
        Schema::table('borrow_transactions', fn (Blueprint $table) => $table->dropColumn('return_note'));
        Schema::table('lend_transactions', fn (Blueprint $table) => $table->dropColumn(['requester_name', 'return_note']));
    }
};
