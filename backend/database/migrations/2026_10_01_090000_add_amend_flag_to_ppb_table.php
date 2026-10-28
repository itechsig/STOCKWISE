<?php

use App\Support\PpbLineStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Amend" becomes a flag next to the line status instead of replacing it, so a
 * line shows e.g. Amend + Requested (user decision 2026-10-01). line_status keeps
 * what Accurate says (REQUESTED / SHORTAGE / COMPLETED / CLOSE). Existing rows are
 * recomputed from their own columns — the next sync writes the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppb', function (Blueprint $table) {
            $table->boolean('is_amend')->default(false)->after('line_status')->index();
            $table->string('amend_source', 10)->nullable()->after('is_amend'); // NOTE (PPB AMEND (…)) / CLOSED (closed, nothing received)
        });

        DB::table('ppb')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $p) {
                $received = $p->qty_diterima !== null ? (float) $p->qty_diterima : null;
                $closed = strtoupper((string) $p->status) === 'CLOSED';
                $listed = $p->kode_barang && in_array(mb_strtoupper($p->kode_barang), PpbLineStatus::amendedCodes($p->keterangan), true);
                $source = PpbLineStatus::amendSource($received, $closed, $listed);
                DB::table('ppb')->where('id', $p->id)->update([
                    'line_status' => PpbLineStatus::of($p->kuantitas !== null ? (float) $p->kuantitas : null, $received, $closed),
                    'is_amend' => $source !== null,
                    'amend_source' => $source,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ppb', function (Blueprint $table) {
            $table->dropIndex(['is_amend']);
            $table->dropColumn(['is_amend', 'amend_source']);
        });
    }
};
