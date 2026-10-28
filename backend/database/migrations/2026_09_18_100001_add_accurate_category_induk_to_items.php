<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori Induk was always NULL because Accurate's own PARENTITEM chain for
 * this company never has a 0-dot top-level node to read a description from
 * (verified live: only 1 ITEMNO in the whole ITEM table has no dot at all,
 * and it's a junk row with no description — see AccurateSyncService's
 * resolveCategoryHierarchy() docblock).
 *
 * Per explicit user instruction, Kategori Induk is instead derived from the
 * item code's own 3-letter prefix (e.g. SSP.2102 -> SSP) through a fixed
 * translation table the user provided (SSP/PUI/OFN/.../AST) — a Stockwise
 * business rule, not something walked from Accurate's category hierarchy.
 * See AccurateSyncService::KATEGORI_INDUK_MAP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('accurate_category_induk', 60)->nullable()->after('accurate_category_anak_3');
            $table->index('accurate_category_induk');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['accurate_category_induk']);
            $table->dropColumn('accurate_category_induk');
        });
    }
};
