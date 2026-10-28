<?php

namespace App\Services;

use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Ri;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Tracking\TrackingPhotoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retur Pembelian: goods received on an RI go back to the vendor.
 *   DIBUAT    made from an RI — which lines, how many (never more than received
 *             minus what other returns already took), why, and how the vendor settles it
 *   DIKIRIM   sent back to the vendor (optional photo of the shipment)
 *   SELESAI   the vendor replaced the goods (GANTI_BARANG) or credited them (POTONG_TAGIHAN)
 *   DIBATALKAN only before it is sent
 * STOCKWISE only: nothing is written to Accurate.
 */
class PurchaseReturnService
{
    public const PHOTO_ENTITY = 'purchase_return';

    public const STATUS_LABELS = ['DIBUAT' => 'Belum dikirim', 'DIKIRIM' => 'Dikirim ke vendor', 'SELESAI' => 'Selesai', 'DIBATALKAN' => 'Dibatalkan'];

    public const RESOLUTION_LABELS = ['GANTI_BARANG' => 'Ganti barang', 'POTONG_TAGIHAN' => 'Potong tagihan'];

    public function __construct(private DocumentNumberService $numbers, private TrackingPhotoService $photos) {}

    /**
     * Lines of an RI with how much can still be returned.
     *
     * @return Collection<int, array{ri_id:int,kode_barang:?string,deskripsi_barang:?string,satuan:?string,harga_satuan:float,received:float,returned:float,returnable:float}>
     */
    public function riLines(string $noRi, ?int $exceptReturnId = null): Collection
    {
        $lines = Ri::where('no_ri', $noRi)->orderBy('id')->get();
        $taken = PurchaseReturnLine::query()
            ->whereIn('ri_id', $lines->pluck('id'))
            ->whereHas('purchaseReturn', fn ($q) => $q->where('status', '!=', 'DIBATALKAN')
                ->when($exceptReturnId, fn ($q) => $q->where('id', '!=', $exceptReturnId)))
            ->selectRaw('ri_id, SUM(qty) as qty')->groupBy('ri_id')->pluck('qty', 'ri_id');

        return $lines->map(fn (Ri $r) => [
            'ri_id' => $r->id, 'kode_barang' => $r->kode_barang, 'deskripsi_barang' => $r->deskripsi_barang, 'satuan' => $r->satuan,
            'harga_satuan' => (float) $r->harga_satuan, 'received' => (float) $r->kuantitas, 'returned' => (float) ($taken[$r->id] ?? 0),
            'returnable' => max(0, round((float) $r->kuantitas - (float) ($taken[$r->id] ?? 0), 2)),
        ])->values();
    }

    /**
     * @param  array{no_ri:string,tgl_retur?:?string,resolution?:?string,reason:string,note?:?string,photo?:?string,lines:list<array{ri_id:int,qty:float}>}  $data
     */
    public function create(User $user, array $data): PurchaseReturn
    {
        $made = DB::transaction(function () use ($user, $data) {
            // lock the RI's lines so two returns can't both take the last unit
            $ri = Ri::where('no_ri', $data['no_ri'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($ri->isEmpty()) {
                throw ValidationException::withMessages(['no_ri' => 'RI tidak ditemukan.']);
            }
            $available = $this->riLines($data['no_ri'])->keyBy('ri_id');
            $wanted = collect($data['lines'])->filter(fn ($l) => (float) $l['qty'] > 0);
            if ($wanted->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Pilih minimal satu barang yang diretur.']);
            }
            foreach ($wanted as $i => $l) {
                $a = $available[$l['ri_id']] ?? null;
                if (! $a) {
                    throw ValidationException::withMessages(["lines.{$i}.ri_id" => 'Barang ini bukan bagian dari RI tersebut.']);
                }
                if ((float) $l['qty'] > $a['returnable'] + 0.0001) {
                    throw ValidationException::withMessages(["lines.{$i}.qty" => "Jumlah retur {$a['deskripsi_barang']} maksimal ".rtrim(rtrim(number_format($a['returnable'], 2, '.', ''), '0'), '.')." {$a['satuan']}."]);
                }
            }
            $first = $ri->first();
            $return = PurchaseReturn::create([
                'number' => $this->numbers->nextShort('RTR'),
                'tgl_retur' => $data['tgl_retur'] ?? now()->toDateString(),
                'no_ri' => $data['no_ri'],
                'no_po' => $ri->pluck('no_po')->filter()->first(),
                'vendor' => $first->vendor,
                'vendor_id' => $first->vendor_id,
                'resolution' => $data['resolution'] ?? 'GANTI_BARANG',
                'reason' => mb_substr(trim($data['reason']), 0, 255),
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'status' => 'DIBUAT',
                'created_by' => $user->id,
            ]);
            foreach ($wanted as $l) {
                $r = $ri[$l['ri_id']];
                $return->lines()->create([
                    'ri_id' => $r->id, 'kode_barang' => $r->kode_barang, 'deskripsi_barang' => $r->deskripsi_barang,
                    'qty' => round((float) $l['qty'], 2), 'satuan' => $r->satuan, 'harga_satuan' => $r->harga_satuan, 'ri_qty' => $r->kuantitas,
                ]);
            }
            if (filled($data['photo'] ?? null)) {
                $this->photos->putDataUrl(self::PHOTO_ENTITY, $return->id, 'barang', $data['photo'], 'foto-barang', $user->id);
            }

            return $return;
        });

        NotificationDispatcher::toPermission('purchase_return.view', 'purchase', 'info', "Retur pembelian {$made->number}",
            "Ke {$made->vendor}: {$made->reason}", "/purchase-returns/{$made->id}", $user->id);

        return $made;
    }

    /** DIBUAT → DIKIRIM: the goods left for the vendor. */
    public function send(PurchaseReturn $return, User $user, ?string $note, ?string $photo): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $user, $note, $photo) {
            $return = PurchaseReturn::lockForUpdate()->findOrFail($return->id);
            $this->expect($return, 'DIBUAT', 'Retur ini sudah '.self::STATUS_LABELS[$return->status].'.');
            if (filled($photo)) {
                $this->photos->putDataUrl(self::PHOTO_ENTITY, $return->id, 'kirim', $photo, 'bukti-kirim', $user->id);
            }
            $return->update(['status' => 'DIKIRIM', 'sent_at' => now(), 'sent_by' => $user->id, 'sent_note' => filled($note) ? mb_substr(trim($note), 0, 255) : null]);

            return $return;
        });
    }

    /** DIKIRIM → SELESAI: replaced (with the new RI number) or credited (with the credit note number). */
    public function resolve(PurchaseReturn $return, User $user, ?string $ref, ?string $note): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $user, $ref, $note) {
            $return = PurchaseReturn::lockForUpdate()->findOrFail($return->id);
            $this->expect($return, 'DIKIRIM', $return->status === 'DIBUAT' ? 'Tandai dikirim ke vendor dulu.' : 'Retur ini sudah '.self::STATUS_LABELS[$return->status].'.');
            $return->update([
                'status' => 'SELESAI', 'resolved_at' => now(), 'resolved_by' => $user->id,
                'resolution_ref' => filled($ref) ? mb_substr(trim($ref), 0, 100) : null,
                'resolution_note' => filled($note) ? mb_substr(trim($note), 0, 255) : null,
            ]);

            return $return;
        });
    }

    public function cancel(PurchaseReturn $return): PurchaseReturn
    {
        $this->expect($return, 'DIBUAT', 'Retur yang sudah dikirim ke vendor tidak bisa dibatalkan.');
        $return->update(['status' => 'DIBATALKAN', 'cancelled_at' => now()]);

        return $return;
    }

    private function expect(PurchaseReturn $return, string $status, string $message): void
    {
        if ($return->status !== $status) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }
}
