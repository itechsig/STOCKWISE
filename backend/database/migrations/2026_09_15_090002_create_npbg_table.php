<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NPBG — mirror of Accurate ARINV (header) + ARINVDET (line) via Sync Accurate.
 * One row per ARINVDET line (header fields like no_npbg/tgl_npbg are duplicated
 * onto every line of the same invoice, matching the original Excel-derived NPBG
 * shape — see docs/excel-data-mapping.md and the mapping in AccurateSyncService).
 *
 * Identity: an ARINVDET line is uniquely identified by (ARINVOICEID, SEQ) — verified
 * against the real GDB schema (docs/GDB_ANALYSIS.md), not guessed. INVOICENO alone is
 * NOT unique because one invoice can have many detail lines.
 *
 * Column ownership (do not conflate — see the master brief §11):
 * - accurate-owned (overwritten every sync): no_npbg, tgl_npbg, shipdate, taxdate,
 *   deskripsi_barang, kuantitas, satuan, peminta, divisi, pelanggan, keterangan.
 * - stockwise-owned (sync must never touch these once set by a user): tipe_npbg,
 *   klasifikasi, deskripsi, nama_proyek, no_seri_nopol, dikeluarkan_oleh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('npbg', function (Blueprint $table) {
            $table->id();

            // Identity back to Accurate — ARINV.ARINVOICEID + ARINVDET.SEQ.
            $table->unsignedInteger('accurate_arinvoice_id');
            $table->unsignedInteger('accurate_seq');

            // Accurate-owned (ARINV header fields).
            $table->string('no_npbg', 60)->nullable();
            $table->date('tgl_npbg')->nullable();
            $table->date('shipdate')->nullable();
            $table->date('taxdate')->nullable();
            $table->string('divisi', 200)->nullable();
            $table->string('pelanggan', 200)->nullable();
            $table->text('keterangan')->nullable();

            // Stockwise-owned — no Accurate source yet, never set by sync (§6).
            $table->string('tipe_npbg', 30)->nullable();
            $table->string('klasifikasi', 50)->nullable();
            $table->string('deskripsi', 500)->nullable();
            $table->string('nama_proyek', 200)->nullable();
            $table->string('no_seri_nopol', 100)->nullable();
            $table->string('dikeluarkan_oleh', 150)->nullable();

            // Accurate-owned (ARINVDET line fields).
            $table->string('deskripsi_barang', 400)->nullable();
            $table->decimal('kuantitas', 14, 2)->nullable();
            $table->string('satuan', 30)->nullable();
            $table->string('peminta', 150)->nullable();

            $table->timestamp('accurate_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['accurate_arinvoice_id', 'accurate_seq']);
            $table->index('no_npbg');
            $table->index('tgl_npbg');
            $table->index('peminta');
            $table->index('divisi');
            $table->index('pelanggan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('npbg');
    }
};
