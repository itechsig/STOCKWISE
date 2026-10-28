<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Klarifikasi/Verifikasi Barang — untuk kasus barang yang diissue lewat NPBG
 * ternyata berbeda type/spesifikasi dari yang diminta. Terpisah dari `npbg`
 * sendiri (bukan kolom di situ) karena hanya sebagian kecil baris NPBG yang
 * pernah punya kasus seperti ini — menaruh status di setiap baris npbg (8000+
 * baris mirror Accurate) akan memberi status default yang tidak berarti pada
 * hampir semua baris.
 *
 * Status flow (linear, sesuai spesifikasi):
 * DIAJUKAN -> DIPROSES -> ALTERNATIF_DITAWARKAN -> MENUNGGU_RESPON
 *   -> (Maintenance terima) -> DISETUJUI -> SELESAI
 *   -> (Maintenance tolak, wajib alasan) -> PERLU_VERIFIKASI_BOS
 *        -> BOS memutuskan -> DISETUJUI/DITOLAK -> SELESAI
 * `escalate()` juga bisa dipanggil manual dari status non-terminal untuk
 * langsung minta keputusan BOS tanpa menunggu respon Maintenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('npbg_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npbg_id')->constrained('npbg')->cascadeOnDelete();
            $table->string('status', 30)->default('DIAJUKAN')->index();

            $table->text('alasan_pengajuan')->nullable();
            $table->text('deskripsi_alternatif')->nullable();
            $table->string('respon_maintenance', 20)->nullable(); // ACCEPT / REJECT
            $table->text('alasan_penolakan')->nullable();
            $table->string('keputusan_bos', 20)->nullable(); // DISETUJUI / DITOLAK
            $table->text('catatan_bos')->nullable();

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('npbg_verifications');
    }
};
