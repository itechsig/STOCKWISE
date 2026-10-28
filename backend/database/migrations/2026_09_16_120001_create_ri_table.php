<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RI — mirror of Accurate APINV (header) + APITMDET (item lines) via Sync
 * Accurate. One row per APITMDET line, same flat shape as `npbg`/`ppb` — see
 * the mapping in AccurateSyncService::syncRi().
 *
 * Identity: an APITMDET line is uniquely identified by (APINVOICEID, SEQ) —
 * verified against the real GDB schema (docs/GDB_ANALYSIS.md). INVOICENO alone
 * is NOT unique because one AP invoice can carry many item lines.
 *
 * Confirmed against live data that APINV really is the RI document: its
 * INVOICENO is literally formatted "RI/{divisi}/{yy}/{roman}/{seq}" (e.g.
 * RI/NV/25/IX/001, RI/ATK/25/IX/006) — the exact same prefix convention the
 * internal `receivings` table already uses (prefix NV) — and APITMDET even
 * carries a self-referencing RIID FK back to APINV.
 *
 * This is a separate, read-only mirror from the app's own `receivings` /
 * `receiving_items` tables (the internal DRAFT->CHECKING->CONFIRMED workflow
 * tied to a PO and to stock movements) — there is no naming collision to
 * resolve here (unlike npbg/ppb), just a new Accurate-sourced data source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ri', function (Blueprint $table) {
            $table->id();

            // Identity back to Accurate — APINV.APINVOICEID + APITMDET.SEQ.
            $table->unsignedInteger('accurate_apinvoice_id');
            $table->unsignedInteger('accurate_seq');

            // APINV header fields.
            $table->string('no_ri', 60)->nullable();
            $table->date('tgl_ri')->nullable();
            $table->string('divisi', 30)->nullable(); // diturunkan dari segmen no_ri
            $table->string('vendor', 200)->nullable();
            $table->string('no_po', 60)->nullable();
            $table->date('shipdate')->nullable();
            $table->text('keterangan')->nullable();

            // APITMDET line fields.
            $table->string('kode_barang', 60)->nullable();
            $table->string('deskripsi_barang', 400)->nullable();
            $table->decimal('kuantitas', 14, 2)->nullable();
            $table->string('satuan', 30)->nullable();
            $table->decimal('harga_satuan', 16, 2)->nullable();
            $table->string('pemeriksa', 150)->nullable();

            $table->timestamp('accurate_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['accurate_apinvoice_id', 'accurate_seq']);
            $table->index('no_ri');
            $table->index('tgl_ri');
            $table->index('kode_barang');
            $table->index('vendor');
            $table->index('divisi');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ri');
    }
};
