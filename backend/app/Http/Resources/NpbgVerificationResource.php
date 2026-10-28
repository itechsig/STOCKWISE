<?php

namespace App\Http\Resources;

use App\Models\NpbgVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NpbgVerification */
class NpbgVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'npbg_id' => $this->npbg_id,
            'status' => $this->status,
            'alasan_pengajuan' => $this->alasan_pengajuan,
            'deskripsi_alternatif' => $this->deskripsi_alternatif,
            'respon_maintenance' => $this->respon_maintenance,
            'alasan_penolakan' => $this->alasan_penolakan,
            'keputusan_bos' => $this->keputusan_bos,
            'catatan_bos' => $this->catatan_bos,
            'opened_by' => $this->whenLoaded('openedBy', fn () => $this->openedBy?->name),
            'responded_by' => $this->whenLoaded('respondedBy', fn () => $this->respondedBy?->name),
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'escalated_at' => $this->escalated_at,
            'responded_at' => $this->responded_at,
            'decided_at' => $this->decided_at,
            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
            'logs' => $this->whenLoaded('logs', fn () => $this->logs->map(fn ($l) => [
                'id' => $l->id,
                'actor' => $l->relationLoaded('actor') ? $l->actor?->name : null,
                'action' => $l->action,
                'from_status' => $l->from_status,
                'to_status' => $l->to_status,
                'note' => $l->note,
                'has_attachment' => (bool) $l->attachment_path,
                'attachment_url' => $l->attachment_path ? route('api.npbg.verifications.attachment', $l->id) : null,
                'created_at' => $l->created_at,
            ])),
        ];
    }
}
