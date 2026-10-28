<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NPBG / RI columns as the current Accurate print templates name them
 * (TEMPLDET of "NPBG [A5]" #133 and "RI [A5]" #130, audit 2026-10-05,
 * docs/npbg-ri-mapping-audit.md):
 *   NPBG  Klasifikasi [Ship Via] → klasifikasi (was jenis_pengeluaran, same meaning)
 *         No. Seri [SNLine]       → no_seri (was the never-filled no_seri_nopol)
 *   RI    No. Surat Jalan [Invoice Number], Pengirim [Ship Via], FOB,
 *         No. PPB [Request No], No. Seri [SNLine]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ri', function (Blueprint $t) {
            $t->string('no_surat_jalan', 60)->nullable()->index()->after('no_ri');
            $t->string('pengirim', 60)->nullable()->after('no_po');
            $t->string('fob', 30)->nullable()->after('pengirim');
            $t->string('no_ppb', 60)->nullable()->index()->after('accurate_po_item_id');
            $t->text('no_seri')->nullable()->after('lokasi_rak');
        });

        // klasifikasi takes over jenis_pengeluaran (klasifikasi was never filled: 0 rows)
        DB::table('npbg')->whereNotNull('jenis_pengeluaran')->update(['klasifikasi' => DB::raw('jenis_pengeluaran')]);
        Schema::table('npbg', function (Blueprint $t) {
            $t->dropIndex('npbg_jenis_pengeluaran_index');
        });
        Schema::table('npbg', function (Blueprint $t) {
            $t->dropColumn('jenis_pengeluaran');
            $t->index('klasifikasi');
        });

        // no_seri_nopol (0 rows filled) → no_seri, long enough for Accurate's SNLine
        Schema::table('npbg', function (Blueprint $t) {
            $t->renameColumn('no_seri_nopol', 'no_seri');
        });
        Schema::table('npbg', function (Blueprint $t) {
            $t->text('no_seri')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('npbg', function (Blueprint $t) {
            $t->string('no_seri', 100)->nullable()->change();
        });
        Schema::table('npbg', function (Blueprint $t) {
            $t->renameColumn('no_seri', 'no_seri_nopol');
        });
        Schema::table('npbg', function (Blueprint $t) {
            $t->dropIndex(['klasifikasi']);
            $t->string('jenis_pengeluaran', 60)->nullable()->index()->after('divisi');
        });
        DB::table('npbg')->whereNotNull('klasifikasi')->update(['jenis_pengeluaran' => DB::raw('klasifikasi')]);
        Schema::table('ri', function (Blueprint $t) {
            $t->dropColumn(['no_surat_jalan', 'pengirim', 'fob', 'no_ppb', 'no_seri']);
        });
    }
};
