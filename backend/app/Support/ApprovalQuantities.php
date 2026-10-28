<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Quantities a Manager / Direktur changes while approving a document (PPB, NPBG): each above 0,
 * and a note is required once any of them changed (everyone sees why).
 */
final class ApprovalQuantities
{
    /**
     * Manager / Direktur no longer change quantities while approving: a quantity that is not right
     * goes back to admin with "Kembalikan" (admin revises it and asks the approval again).
     */
    public static function refuseFromApprover(mixed $quantities): void
    {
        if (! empty($quantities)) {
            throw ValidationException::withMessages(['quantities' => 'Jumlah tidak bisa diubah saat approval. Klik "Kembalikan" agar admin merevisi jumlahnya.']);
        }
    }

    /**
     * @param  Collection<int, Model>  $rows  the document's rows (with kuantitas, deskripsi_barang)
     * @param  array<int, float>  $quantities  row id => new qty
     * @return list<string> what changed, e.g. "KERTAS 10→8" (rows updated by the caller via $apply)
     */
    public static function changes(Collection $rows, array $quantities, ?string $note): array
    {
        $changed = [];
        foreach ($quantities as $id => $q) {
            $row = $rows->firstWhere('id', (int) $id);
            if (! $row) {
                throw ValidationException::withMessages(["quantities.{$id}" => 'Baris ini bukan bagian dari dokumen ini.']);
            }
            $q = round((float) $q, 2);
            if ($q <= 0) {
                throw ValidationException::withMessages(["quantities.{$id}" => "Jumlah {$row->deskripsi_barang} harus lebih dari 0."]);
            }
            if (abs($q - (float) $row->kuantitas) > 0.0001) {
                $changed[(int) $id] = $q;
            }
        }
        if ($changed !== [] && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Jumlah diubah: wajib isi catatan alasannya.']);
        }

        return $changed;
    }

    /** "Jumlah diubah: KERTAS 10→8. <note>" (or the note alone) */
    public static function note(Collection $rows, array $changed, ?string $note): ?string
    {
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $parts = collect($changed)->map(function ($q, $id) use ($rows, $num) {
            $row = $rows->firstWhere('id', $id);

            return "{$row->deskripsi_barang} {$num($row->kuantitas)}→{$num($q)}";
        })->implode(', ');
        $text = trim(($parts !== '' ? "Jumlah diubah: {$parts}. " : '').trim((string) $note));

        return $text !== '' ? $text : null;
    }
}
