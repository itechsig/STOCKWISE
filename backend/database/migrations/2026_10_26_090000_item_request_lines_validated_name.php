<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Once admin gudang matched a Request Barang line to Master Produk, the line carries the master
 * name (kode, deskripsi, satuan) from then on; what the requester typed is kept in deskripsi_peminta.
 * Lines matched before this: converted, and the system notes of their history name the master item.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('item_request_lines', 'deskripsi_peminta')) {
            Schema::table('item_request_lines', function (Blueprint $table) {
                $table->string('deskripsi_peminta', 400)->nullable()->after('deskripsi_barang');
            });
        }

        $lines = DB::table('item_request_lines as l')->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('units as u', 'u.id', '=', 'i.unit_id')
            ->whereNull('l.deskripsi_peminta')
            ->get(['l.id', 'l.item_request_id', 'l.deskripsi_barang', 'l.satuan', 'i.code', 'i.description', 'u.code as unit']);
        foreach ($lines as $l) {
            DB::table('item_request_lines')->where('id', $l->id)->update([
                'deskripsi_peminta' => $l->deskripsi_barang, 'deskripsi_barang' => mb_substr((string) $l->description, 0, 400),
                'kode_barang' => $l->code, 'satuan' => $l->unit ?: $l->satuan,
            ]);
            if ($l->deskripsi_barang === $l->description) {
                continue;
            }
            // the system's notes name an item as "<name> <qty>": those say the master name now (people's own words stay)
            $pattern = '/(?<![\pL\pN])'.preg_quote($l->deskripsi_barang, '/').'(?= \d)/u';
            foreach (DB::table('item_request_approvals')->where('item_request_id', $l->item_request_id)->whereNotNull('note')->get(['id', 'note']) as $a) {
                $note = preg_replace($pattern, (string) $l->description, $a->note);
                if ($note !== null && $note !== $a->note) {
                    DB::table('item_request_approvals')->where('id', $a->id)->update(['note' => mb_substr($note, 0, 500)]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('item_request_lines')->whereNotNull('deskripsi_peminta')->update(['deskripsi_barang' => DB::raw('deskripsi_peminta')]);
        Schema::table('item_request_lines', function (Blueprint $table) {
            $table->dropColumn('deskripsi_peminta');
        });
    }
};
