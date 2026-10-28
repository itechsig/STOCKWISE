<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lend / Borrow are derived from Accurate on every sync (user decision
 * 2026-09-29, no admin action): Lend out = an NPBG line, Borrow in = an RI
 * line. Same stale FK as STPP had (see 2026_09_19_035903): out_npbg_id /
 * return_npbg_id still pointed at the renamed, empty `goods_issues` table —
 * repointed to the real Accurate `npbg` mirror (both tables were empty).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lend_transactions', function (Blueprint $table) {
            $table->dropForeign(['out_npbg_id']);
            $table->foreign('out_npbg_id')->references('id')->on('npbg')->cascadeOnDelete();
            $table->unique('out_npbg_id');
            $table->string('source', 10)->default('MANUAL')->after('status')->index(); // MANUAL / ACCURATE
            $table->string('return_refs', 500)->nullable()->after('return_date');     // RI numbers that returned it
        });

        Schema::table('borrow_transactions', function (Blueprint $table) {
            $table->dropForeign(['return_npbg_id']);
            $table->foreign('return_npbg_id')->references('id')->on('npbg')->nullOnDelete();
            $table->foreignId('in_ri_id')->nullable()->after('receipt_ref')->unique()->constrained('ri')->cascadeOnDelete();
            $table->string('source', 10)->default('MANUAL')->after('status')->index();
            $table->string('return_refs', 500)->nullable()->after('returned_at');     // NPBG numbers that returned it
        });
    }

    public function down(): void
    {
        Schema::table('borrow_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('in_ri_id');
            $table->dropColumn(['source', 'return_refs']);
            $table->dropForeign(['return_npbg_id']);
            $table->foreign('return_npbg_id')->references('id')->on('goods_issues')->nullOnDelete();
        });

        Schema::table('lend_transactions', function (Blueprint $table) {
            $table->dropUnique(['out_npbg_id']);
            $table->dropColumn(['source', 'return_refs']);
            $table->dropForeign(['out_npbg_id']);
            $table->foreign('out_npbg_id')->references('id')->on('goods_issues')->nullOnDelete();
        });
    }
};
