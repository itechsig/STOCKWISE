<?php

namespace App\Services;

use App\Models\Npbg;
use App\Models\NpbgVerification;
use App\Models\NpbgVerificationLog;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Klarifikasi/Verifikasi Barang — see App\Models\NpbgVerification for the
 * status flow. Every action here (a) enforces a valid current-status
 * transition and (b) appends an NpbgVerificationLog row — that log is the
 * audit trail, never the mutable columns on npbg_verifications alone.
 */
class NpbgVerificationService
{
    public function open(Npbg $npbg, User $user, string $alasanPengajuan, ?string $attachment = null): NpbgVerification
    {
        if ($npbg->verifications()->whereIn('status', NpbgVerification::OPEN_STATUSES)->exists()) {
            throw ValidationException::withMessages([
                'npbg' => ['NPBG ini sudah punya klarifikasi yang masih berjalan.'],
            ]);
        }

        return DB::transaction(function () use ($npbg, $user, $alasanPengajuan, $attachment) {
            $verification = $npbg->verifications()->create([
                'status' => NpbgVerification::STATUS_DIAJUKAN,
                'alasan_pengajuan' => $alasanPengajuan,
                'opened_by' => $user->id,
            ]);

            $this->log($verification, $user, 'OPEN', null, NpbgVerification::STATUS_DIAJUKAN, $alasanPengajuan, $attachment);

            NotificationDispatcher::toPermission(
                'npbg.verification.manage', 'npbg_verification', 'info',
                'Klarifikasi NPBG baru',
                "NPBG {$npbg->no_npbg} diajukan klarifikasi: {$alasanPengajuan}",
                "/npbg/{$npbg->id}",
                exceptUserId: $user->id,
            );

            return $verification->fresh();
        });
    }

    public function process(NpbgVerification $verification, User $user, ?string $note = null): NpbgVerification
    {
        $this->assert($verification, [NpbgVerification::STATUS_DIAJUKAN]);

        return DB::transaction(function () use ($verification, $user, $note) {
            $this->transition($verification, $user, 'PROCESS', NpbgVerification::STATUS_DIPROSES, $note);

            return $verification->fresh();
        });
    }

    public function offerAlternative(NpbgVerification $verification, User $user, string $deskripsiAlternatif, ?string $note = null, ?string $attachment = null): NpbgVerification
    {
        $this->assert($verification, [NpbgVerification::STATUS_DIPROSES]);

        return DB::transaction(function () use ($verification, $user, $deskripsiAlternatif, $note, $attachment) {
            $verification->update(['deskripsi_alternatif' => $deskripsiAlternatif]);
            $this->transition($verification, $user, 'OFFER_ALTERNATIVE', NpbgVerification::STATUS_ALTERNATIF_DITAWARKAN, $note, $attachment);
            // Menawarkan alternatif otomatis berarti menunggu respon Maintenance.
            $this->transition($verification, $user, 'AWAIT_RESPONSE', NpbgVerification::STATUS_MENUNGGU_RESPON, null);

            return $verification->fresh();
        });
    }

    /** @param 'ACCEPT'|'REJECT' $decision */
    public function respond(NpbgVerification $verification, User $user, string $decision, ?string $reason = null, ?string $attachment = null): NpbgVerification
    {
        $this->assert($verification, [NpbgVerification::STATUS_MENUNGGU_RESPON]);

        if (! in_array($decision, ['ACCEPT', 'REJECT'], true)) {
            throw ValidationException::withMessages(['decision' => ['Keputusan harus ACCEPT atau REJECT.']]);
        }
        if ($decision === 'REJECT' && ! $reason) {
            throw ValidationException::withMessages(['reason' => ['Alasan wajib diisi jika menolak barang alternatif.']]);
        }

        return DB::transaction(function () use ($verification, $user, $decision, $reason, $attachment) {
            $verification->update([
                'respon_maintenance' => $decision,
                'alasan_penolakan' => $decision === 'REJECT' ? $reason : null,
                'responded_by' => $user->id,
                'responded_at' => now(),
            ]);

            if ($decision === 'ACCEPT') {
                $this->transition($verification, $user, 'RESPOND_ACCEPT', NpbgVerification::STATUS_DISETUJUI, $reason, $attachment);
                $this->close($verification, $user);
            } else {
                $verification->update(['escalated_at' => now()]);
                $this->transition($verification, $user, 'RESPOND_REJECT', NpbgVerification::STATUS_PERLU_VERIFIKASI_BOS, $reason, $attachment);
                $this->notifyBos($verification, "NPBG {$verification->npbg->no_npbg}: barang alternatif ditolak, perlu keputusan BOS.");
            }

            return $verification->fresh();
        });
    }

    /** Manual escalation to BOS from any still-open, non-BOS-pending status. */
    public function escalate(NpbgVerification $verification, User $user, ?string $note = null): NpbgVerification
    {
        $this->assert($verification, [
            NpbgVerification::STATUS_DIAJUKAN, NpbgVerification::STATUS_DIPROSES,
            NpbgVerification::STATUS_ALTERNATIF_DITAWARKAN, NpbgVerification::STATUS_MENUNGGU_RESPON,
        ]);

        return DB::transaction(function () use ($verification, $user, $note) {
            $verification->update(['escalated_at' => now()]);
            $this->transition($verification, $user, 'ESCALATE', NpbgVerification::STATUS_PERLU_VERIFIKASI_BOS, $note);
            $this->notifyBos($verification, "NPBG {$verification->npbg->no_npbg} dieskalasi, perlu keputusan BOS.");

            return $verification->fresh();
        });
    }

    /** @param 'DISETUJUI'|'DITOLAK' $keputusan */
    public function bosDecide(NpbgVerification $verification, User $user, string $keputusan, ?string $catatan = null, ?string $attachment = null): NpbgVerification
    {
        $this->assert($verification, [NpbgVerification::STATUS_PERLU_VERIFIKASI_BOS]);

        if (! in_array($keputusan, [NpbgVerification::STATUS_DISETUJUI, NpbgVerification::STATUS_DITOLAK], true)) {
            throw ValidationException::withMessages(['keputusan' => ['Keputusan harus DISETUJUI atau DITOLAK.']]);
        }

        return DB::transaction(function () use ($verification, $user, $keputusan, $catatan, $attachment) {
            $verification->update([
                'keputusan_bos' => $keputusan,
                'catatan_bos' => $catatan,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ]);

            $this->transition($verification, $user, 'BOS_DECIDE', $keputusan, $catatan, $attachment);
            $this->close($verification, $user);

            NotificationDispatcher::toPermission(
                'npbg.verification.manage', 'npbg_verification', $keputusan === NpbgVerification::STATUS_DISETUJUI ? 'success' : 'danger',
                'BOS memutuskan klarifikasi NPBG',
                "NPBG {$verification->npbg->no_npbg}: BOS memutuskan {$keputusan}.".($catatan ? " Catatan: {$catatan}" : ''),
                "/npbg/{$verification->npbg_id}",
                exceptUserId: $user->id,
            );

            return $verification->fresh();
        });
    }

    // ------------------------------------------------------------------

    private function notifyBos(NpbgVerification $verification, string $body): void
    {
        NotificationDispatcher::toPermission(
            'npbg.verification.bos_decide', 'npbg_verification', 'warning',
            'Klarifikasi NPBG perlu keputusan BOS',
            $body,
            "/npbg/{$verification->npbg_id}",
        );
    }

    private function close(NpbgVerification $verification, User $user): void
    {
        $verification->update(['closed_at' => now()]);
        $this->transition($verification, $user, 'CLOSE', NpbgVerification::STATUS_SELESAI, null);
    }

    /** @param list<string> $allowed */
    private function assert(NpbgVerification $verification, array $allowed): void
    {
        if (! in_array($verification->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Aksi tidak valid untuk status klarifikasi {$verification->status}."],
            ]);
        }
    }

    private function transition(NpbgVerification $verification, User $user, string $action, string $toStatus, ?string $note, ?string $attachment = null): void
    {
        $from = $verification->status;
        $verification->update(['status' => $toStatus]);
        $this->log($verification, $user, $action, $from, $toStatus, $note, $attachment);
    }

    private function log(NpbgVerification $verification, User $user, string $action, ?string $from, ?string $to, ?string $note, ?string $attachment = null): NpbgVerificationLog
    {
        return NpbgVerificationLog::create([
            'npbg_verification_id' => $verification->id,
            'actor_id' => $user->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'attachment_path' => $this->storeAttachment($verification, $attachment),
        ]);
    }

    /** Accepts a `data:<mime>;base64,<data>` string. */
    private function storeAttachment(NpbgVerification $verification, ?string $dataUrl): ?string
    {
        if (! $dataUrl || ! Str::startsWith($dataUrl, 'data:')) {
            return null;
        }

        [$meta, $b64] = explode(',', $dataUrl, 2);
        $ext = match (true) {
            Str::contains($meta, 'png') => 'png',
            Str::contains($meta, 'jpeg') || Str::contains($meta, 'jpg') => 'jpg',
            Str::contains($meta, 'pdf') => 'pdf',
            default => 'bin',
        };
        $path = "npbg-verifications/{$verification->id}/".Str::uuid().".{$ext}";
        Storage::disk('local')->put($path, base64_decode($b64));

        return $path;
    }
}
