<?php

namespace App\Support;

/**
 * One rule for "did this Accurate value change" — used by the sync (decides
 * UPDATE vs SKIP) and by the sync history (lists what changed, before → after).
 *
 * Numbers compare as numbers ("1.0000" = 1, "10.00" = 10), dates as Y-m-d, the
 * rest as trimmed text. Before this, every NPBG line was logged as changed on
 * every sync because unit_ratio "1.0000" (DB) !== "1" (Accurate) as strings.
 */
class SyncDiff
{
    /** Field labels as the Excel / screens name them. */
    public const LABELS = [
        // document numbers / dates
        'no_npbg' => 'Nomor NPBG', 'no_ppb' => 'Nomor PPB', 'no_ri' => 'Nomor RI', 'number' => 'Nomor dokumen', 'no_po' => 'Nomor PO',
        'tgl_npbg' => 'Tanggal NPBG', 'tgl_ppb' => 'Tanggal PPB', 'tgl_ri' => 'Tanggal RI', 'date' => 'Tanggal',
        'shipdate' => 'Tanggal kirim', 'taxdate' => 'Tanggal pajak', 'expected_date' => 'Tanggal diharapkan', 'scheduled_date' => 'Tanggal jadwal',
        // parties / notes
        'divisi' => 'Divisi', 'pelanggan' => 'Pelanggan / Kirim ke', 'vendor' => 'Vendor', 'vendor_id' => 'Vendor', 'peminta' => 'Peminta',
        'pemeriksa' => 'Pemeriksa', 'klasifikasi' => 'Klasifikasi', 'no_seri' => 'No. Seri', 'no_surat_jalan' => 'No. Surat Jalan', 'pengirim' => 'Pengirim', 'fob' => 'FOB', 'no_ppb' => 'No. PPB', 'lokasi_gudang' => 'Lokasi gudang', 'lokasi_rak' => 'Lokasi rak', 'keterangan' => 'Keterangan', 'notes' => 'Catatan', 'catatan_baris' => 'Catatan baris',
        // item
        'kode_barang' => 'Kode barang', 'deskripsi_barang' => 'Nama barang', 'description' => 'Nama barang', 'description_raw' => 'Nama barang',
        'item_id' => 'Barang', 'kuantitas' => 'Kuantitas', 'qty' => 'Kuantitas', 'satuan' => 'Satuan', 'unit_id' => 'Satuan', 'unit_ratio' => 'Rasio satuan',
        'qty_dipesan' => 'Qty dipesan', 'qty_diterima' => 'Qty diterima', 'qty_received' => 'Qty diterima', 'harga_satuan' => 'Harga satuan',
        'unit_price' => 'Harga satuan', 'line_total' => 'Total baris', 'subtotal' => 'Subtotal', 'tax' => 'Pajak', 'total' => 'Total',
        'accurate_qty_onhand' => 'Stok Accurate', 'accurate_qty_onorder' => 'Qty dipesan (PO)',
        'accurate_category_induk' => 'Kategori induk', 'accurate_category_anak_1' => 'Kategori anak 1',
        'accurate_category_anak_2' => 'Kategori anak 2', 'accurate_category_anak_3' => 'Kategori anak 3',
        // status
        'status' => 'Status', 'line_status' => 'Status barang', 'is_amend' => 'Amend', 'amend_source' => 'Sumber Amend',
        'accurate_ppb_id' => 'Baris PPB', 'accurate_po_item_id' => 'Baris PO',
        'system_qty' => 'Qty sistem', 'physical_qty' => 'Qty fisik', 'warehouse_id' => 'Gudang',
    ];

    /** The field holding the document number, per sync entity. */
    public const DOC_FIELD = ['npbg' => 'no_npbg', 'ppb' => 'no_ppb', 'ri' => 'no_ri', 'po' => 'number', 'stock_opname' => 'number', 'used_return' => 'number'];

    public const ENTITY_LABELS = [
        'item' => 'Barang', 'npbg' => 'NPBG', 'ppb' => 'PPB', 'ri' => 'RI', 'po' => 'PO', 'stock_opname' => 'Stock Opname',
        'used_return' => 'Barang Bekas Kembali', 'stpp' => 'STPP', 'tyre' => 'Ban', 'lend_borrow' => 'Pinjam', 'stockwise_purge' => 'Hapus data STOCKWISE',
    ];

    public static function same(mixed $a, mixed $b): bool
    {
        $a = self::normalise($a);
        $b = self::normalise($b);
        // numbers as numbers — but "001" vs "1" is a code / document number, not a quantity
        if (is_numeric($a) && is_numeric($b) && ! preg_match('/^0\d/', (string) $a) && ! preg_match('/^0\d/', (string) $b)) {
            return abs((float) $a - (float) $b) < 1e-6;
        }

        return (string) ($a ?? '') === (string) ($b ?? '');
    }

    /** True when no field of $new differs from $current (an object/model/array). */
    public static function unchanged(array $new, mixed $current): bool
    {
        foreach ($new as $f => $v) {
            if (! self::same(is_array($current) ? ($current[$f] ?? null) : $current->{$f}, $v)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fields that really changed, with labels. Lines changed inside a document
     * (PO / opname) come in $new['_lines'] and are appended as "Baris n · …".
     *
     * @return array<int, array{field:string,label:string,from:?string,to:?string}>
     */
    public static function changes(?array $old, ?array $new): array
    {
        if (! $old || ! $new) {
            return [];
        }
        $out = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $f) {
            if ($f === '_lines' || str_starts_with((string) $f, 'accurate_synced') || in_array($f, ['created_at', 'updated_at'], true)) {
                continue;
            }
            if (! self::same($old[$f] ?? null, $new[$f] ?? null)) {
                $out[] = ['field' => $f, 'label' => self::LABELS[$f] ?? $f, 'from' => self::show($old[$f] ?? null), 'to' => self::show($new[$f] ?? null)];
            }
        }
        // the document number first — that is what people look for
        usort($out, fn ($x, $y) => (int) ! str_starts_with($x['label'], 'Nomor') <=> (int) ! str_starts_with($y['label'], 'Nomor'));
        foreach ($new['_lines'] ?? [] as $l) {
            $out[] = [
                'field' => 'line.'.$l['seq'].'.'.$l['field'],
                'label' => 'Baris '.$l['seq'].($l['item'] ? ' ('.$l['item'].')' : '').' · '.(self::LABELS[$l['field']] ?? $l['field']),
                'from' => self::show($l['from']), 'to' => self::show($l['to']),
            ];
        }

        return $out;
    }

    /** What a deleted row held (labelled fields only), as "before → —". */
    public static function removed(?array $old): array
    {
        $out = [];
        foreach ($old ?? [] as $f => $v) {
            if (isset(self::LABELS[$f]) && ($shown = self::show($v)) !== null) {
                $out[] = ['field' => $f, 'label' => self::LABELS[$f], 'from' => $shown, 'to' => null];
            }
        }

        return $out;
    }

    /** Field-by-field changes between two rows, for the "_lines" of a document. */
    public static function lineChanges(int|string $seq, ?string $item, ?array $old, array $new): array
    {
        if ($old === null) {
            return [['seq' => $seq, 'item' => $item, 'field' => 'baris', 'from' => null, 'to' => 'baris baru']];
        }
        $out = [];
        foreach ($new as $f => $v) {
            if (! self::same($old[$f] ?? null, $v)) {
                $out[] = ['seq' => $seq, 'item' => $item, 'field' => $f, 'from' => self::raw($old[$f] ?? null), 'to' => self::raw($v)];
            }
        }

        return $out;
    }

    private static function normalise(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_string($v)) {
            $v = trim($v);
            // "2026-10-01 00:00:00" / "2026-10-01T00:00:00.000000Z" → the date only
            if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]00:00:00(\.0+)?Z?$/', $v, $m)) {
                return $m[1];
            }
        }

        return $v;
    }

    private static function raw(mixed $v): mixed
    {
        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
    }

    private static function show(mixed $v): ?string
    {
        $v = self::normalise($v);
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && ! preg_match('/^0\d/', (string) $v)) {
            $f = (float) $v;

            return abs($f - round($f)) < 1e-9 ? (string) (int) round($f) : rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
        }

        return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}
