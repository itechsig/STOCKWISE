<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tipe NPBG comes from Accurate since 2026-10-21 (ARINV.TERMSID → TERMOPMT.TERMMEMO:
 * "PENJUALAN" / "NON-PENJUALAN", see AccurateLineFields::npbgType). Fill the NPBG
 * already synced once, here, so the next sync doesn't log every line as an UPDATE.
 * Only rows from Accurate; a manual NPBG keeps the type its maker chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accurate_arinv') || ! Schema::hasTable('accurate_termopmt') || ! Schema::hasColumn('accurate_arinv', 'TERMSID')) {
            return;
        }
        $memo = DB::table('accurate_termopmt')->pluck('TERMMEMO', 'TERMID');
        $type = fn ($m) => match (strtoupper(preg_replace('/[\s_-]+/', '', (string) $m))) {
            'PENJUALAN' => 'Penjualan',
            'NONPENJUALAN' => 'Non Penjualan',
            default => null,
        };
        foreach (DB::table('accurate_arinv')->whereNotNull('TERMSID')->get(['ARINVOICEID', 'TERMSID'])->groupBy('TERMSID') as $termId => $docs) {
            $t = $type($memo[$termId] ?? null);
            if ($t === null) {
                continue;
            }
            foreach ($docs->pluck('ARINVOICEID')->chunk(500) as $ids) {
                DB::table('npbg')->whereIn('accurate_arinvoice_id', $ids->all())->update(['tipe_npbg' => $t]);
            }
        }
    }

    public function down(): void
    {
        DB::table('npbg')->whereNotNull('accurate_arinvoice_id')->update(['tipe_npbg' => null]);
    }
};
