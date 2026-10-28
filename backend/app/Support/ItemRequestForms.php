<?php

namespace App\Support;

/**
 * The Request Barang forms. Every form has the same item lines; they differ in
 * one extra field (form_detail, with its own label), whether urgency is asked
 * (no form asks it any more: admin gudang marks a request urgent afterwards —
 * ItemRequestController::urgency — otherwise everyone would pick "urgent")
 * (ATK) and the divisi they start with. UMUM = the first, general form (requests
 * and links made before the forms existed).
 */
class ItemRequestForms
{
    public const URGENCIES = ['URGENT', 'NORMAL'];

    /** type => [label, detail field label (null = none), asks urgency, default divisi] */
    public const TYPES = [
        'ATK' => ['Permintaan ATK', null, false, null],
        'MAINTENANCE' => ['Maintenance General', 'Untuk unit / mesin / lokasi', false, 'MAINTENANCE'],
        'DISTRIBUSI' => ['Permintaan Distribusi', 'Untuk armada / tujuan', false, 'DISTRIBUSI'],
        'MANUFAKTUR' => ['Manufaktur & Assembly', 'Nama produk / proyek', false, 'MANUFAKTUR'],
        'UMUM' => ['Request Umum', null, false, null],
    ];

    public static function label(?string $type): string
    {
        return self::TYPES[$type ?? 'UMUM'][0] ?? (string) $type;
    }

    public static function asksUrgency(?string $type): bool
    {
        return self::TYPES[$type ?? 'UMUM'][2] ?? false;
    }

    /** @return array{type:string,label:string,detail_label:?string,urgency:bool,default_divisi:?string} */
    public static function describe(string $type): array
    {
        [$label, $detail, $urgency, $divisi] = self::TYPES[$type] ?? self::TYPES['UMUM'];

        return ['type' => $type, 'label' => $label, 'detail_label' => $detail, 'urgency' => $urgency, 'default_divisi' => $divisi];
    }

    /** @return list<array{type:string,label:string,detail_label:?string,urgency:bool,default_divisi:?string}> */
    public static function all(): array
    {
        return array_map(fn ($t) => self::describe($t), array_keys(self::TYPES));
    }
}
