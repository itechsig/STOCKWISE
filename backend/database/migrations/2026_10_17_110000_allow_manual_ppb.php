<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow manually-created PPB rows ("Tambah PPB") to coexist with Accurate-synced
 * ones — same convention as 2026_10_17_100000_allow_manual_npbg.php: accurate_reqid/
 * accurate_seq become nullable (NULL,NULL pairs in the unique index are distinct in
 * MySQL), "is this row from Accurate?" is "is accurate_reqid null?". tgl_diperlukan
 * (needed-by date) is new — nothing in the Accurate REQUISITION mirror carries it.
 *
 * Every step is guarded (hasColumn / explicit FK name check) because this migration
 * partially ran once already: the FK Laravel auto-names "ppb_created_by_foreign" turned
 * out to already be taken (MySQL foreign key names are schema-wide, not per-table) by
 * the legacy `purchase_proposals` table (the old `ppb` renamed in
 * 2026_09_16_100001_rename_ppb_to_purchase_proposals.php) — the column adds upstream of
 * it had already committed (MySQL DDL auto-commits) before that failure.
 */
return new class extends Migration
{
    private const FK_NAME = 'ppb_created_by_users_foreign';

    public function up(): void
    {
        Schema::table('ppb', function (Blueprint $table) {
            $table->unsignedInteger('accurate_reqid')->nullable()->change();
            $table->unsignedInteger('accurate_seq')->nullable()->change();
        });

        if (! Schema::hasColumn('ppb', 'tgl_diperlukan')) {
            Schema::table('ppb', function (Blueprint $table) {
                $table->date('tgl_diperlukan')->nullable()->after('tgl_ppb');
            });
        }

        if (! Schema::hasColumn('ppb', 'created_by')) {
            Schema::table('ppb', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable()->after('tgl_diperlukan');
            });
        }

        if (! $this->foreignKeyExists('ppb', self::FK_NAME)) {
            Schema::table('ppb', function (Blueprint $table) {
                $table->foreign('created_by', self::FK_NAME)->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('ppb', function (Blueprint $table) {
            if ($this->foreignKeyExists('ppb', self::FK_NAME)) {
                $table->dropForeign(self::FK_NAME);
            }
        });
        Schema::table('ppb', function (Blueprint $table) {
            $table->dropColumn(['created_by', 'tgl_diperlukan']);
            $table->unsignedInteger('accurate_reqid')->nullable(false)->change();
            $table->unsignedInteger('accurate_seq')->nullable(false)->change();
        });
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $name]
        ) !== null;
    }
};
