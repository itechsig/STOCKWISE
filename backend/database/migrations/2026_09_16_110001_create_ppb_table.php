<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPB — mirror of Accurate REQUISITION (header) + REQUISITIONDET (line) via Sync
 * Accurate. One row per REQUISITIONDET line (header fields like no_ppb/tgl_ppb are
 * duplicated onto every line of the same requisition), matching the same flat shape
 * already used for `npbg` — see the mapping in AccurateSyncService::syncPpb().
 *
 * Identity: a REQUISITIONDET line is uniquely identified by (REQID, SEQ) — verified
 * against the real GDB schema (docs/GDB_ANALYSIS.md), not guessed. REQNO alone is
 * NOT unique because one requisition can have many detail lines.
 *
 * Real REQNO values are literally formatted "PPB/{divisi}/{yy}/{roman}/{seq}"
 * (e.g. PPB/ATK/25/IX/004) — confirmed against live accurate_requisition data —
 * so `divisi` is derived from that number rather than a dedicated column (Accurate
 * has none here, unlike ARINV.SHIPTO1 for NPBG).
 *
 * This is a read-only mirror — no Stockwise-owned fields, no update endpoint,
 * unlike Npbg (which carries manual enrichment columns per an explicit brief).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppb', function (Blueprint $table) {
            $table->id();

            // Identity back to Accurate — REQUISITION.REQID + REQUISITIONDET.SEQ.
            $table->unsignedInteger('accurate_reqid');
            $table->unsignedInteger('accurate_seq');

            // REQUISITION header fields.
            $table->string('no_ppb', 60)->nullable();
            $table->date('tgl_ppb')->nullable();
            $table->string('status', 10)->nullable(); // OPEN / CLOSED, dari ISCLOSED
            $table->string('divisi', 30)->nullable(); // diturunkan dari segmen no_ppb
            $table->text('keterangan')->nullable(); // REQUISITION.DESCRIPTION

            // REQUISITIONDET line fields.
            $table->string('kode_barang', 60)->nullable();
            $table->string('deskripsi_barang', 400)->nullable();
            $table->decimal('kuantitas', 14, 2)->nullable();
            $table->string('satuan', 30)->nullable();
            $table->decimal('qty_dipesan', 14, 2)->nullable();
            $table->decimal('qty_diterima', 14, 2)->nullable();
            $table->string('peminta', 150)->nullable();
            $table->string('catatan_baris', 255)->nullable(); // REQUISITIONDET.NOTES

            $table->timestamp('accurate_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['accurate_reqid', 'accurate_seq']);
            $table->index('no_ppb');
            $table->index('tgl_ppb');
            $table->index('kode_barang');
            $table->index('peminta');
            $table->index('divisi');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppb');
    }
};
