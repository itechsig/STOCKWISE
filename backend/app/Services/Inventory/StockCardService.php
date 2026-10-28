<?php

namespace App\Services\Inventory;

use App\Models\StockCard;
use App\Services\Tracking\TrackingPhotoService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Kartu Stock as the paper card "KARTU STOCK BARANG" (used e.g. for the CCTV
 * installation project): created per item from Master Barang, lines written by
 * hand — Tgl, No. Bukti, Keterangan, Masuk, Keluar — and Sisa computed in card
 * order (date, then as written). Each line can carry any number of files — photos
 * or PDFs (tracking_photos entity "stock_card_entry", one slot per file).
 */
class StockCardService
{
    public const PHOTO_ENTITY = 'stock_card_entry';

    /** @return array<string, mixed> */
    public function present(StockCard $card): array
    {
        $card->loadMissing('item.unit', 'entries', 'project');
        $files = app(TrackingPhotoService::class)->listForRows(self::PHOTO_ENTITY, $card->entries->pluck('id'));

        $sisa = 0.0;
        $rows = $card->entries->map(function ($e) use (&$sisa, $files) {
            $sisa = round($sisa + (float) $e->qty_in - (float) $e->qty_out, 4);

            return [
                'id' => $e->id,
                'date' => $e->entry_date?->toDateString(),
                'doc_no' => $e->doc_no,
                'description' => $e->description,
                'masuk' => (float) $e->qty_in,
                'keluar' => (float) $e->qty_out,
                'sisa' => $sisa,
                'files' => $files[$e->id] ?? [],
                'updated_at' => $e->updated_at,
            ];
        })->values();

        return [
            'id' => $card->id,
            'card_number' => $card->card_number,
            'project' => $card->project ? ['id' => $card->project->id, 'name' => $card->project->name, 'code' => $card->project->code, 'status' => $card->project->status] : null,
            'item' => [
                'id' => $card->item?->id, 'code' => $card->item?->code, 'description' => $card->item?->description,
                'unit' => $card->item?->unit?->code,
                // for information only: the card is written by hand, Accurate's stock is not its source
                'accurate_qty' => $card->item?->accurate_qty_onhand !== null ? (float) $card->item->accurate_qty_onhand : null,
            ],
            'location_warehouse' => $card->location_warehouse,
            'location_rack' => $card->location_rack,
            'person_in_charge' => $card->person_in_charge,
            'director_name' => $card->director_name,
            'rows' => $rows,
            'total_masuk' => round((float) $rows->sum('masuk'), 4),
            'total_keluar' => round((float) $rows->sum('keluar'), 4),
            'sisa' => $sisa,
            'created_at' => $card->created_at,
            'updated_at' => $card->updated_at,
        ];
    }

    /**
     * Printable HTML — one card, or every card of a project (one per page). The PDF is printed from it.
     *
     * @param  StockCard|iterable<StockCard>  $cards
     */
    public function html(StockCard|iterable $cards, ?string $title = null): string
    {
        $cards = $cards instanceof StockCard ? [$cards] : $cards;
        $presented = collect($cards)->map(fn (StockCard $c) => $this->present($c))->values()->all();

        return View::make('stock-card.print', [
            'cards' => $presented,
            'title' => $title ?? ('Kartu Stock '.($presented[0]['card_number'] ?? '')),
        ])->render();
    }

    /**
     * PDF of the card, printed by the server's own Google Chrome (headless) from
     * html() — no PDF library needed, and it looks exactly like the print.
     */
    /** @param  StockCard|iterable<StockCard>  $cards */
    public function pdf(StockCard|iterable $cards, ?string $title = null): string
    {
        File::ensureDirectoryExists(storage_path('app/tmp-pdf'));
        $chrome = config('stockwise.chrome_path');
        if (! $chrome || ! is_file($chrome)) {
            throw new RuntimeException('Google Chrome tidak ditemukan di server (stockwise.chrome_path) — PDF tidak bisa dibuat.');
        }
        // folders an earlier run could not remove (Chrome still held its profile)
        foreach (File::directories(storage_path('app/tmp-pdf')) as $old) {
            if (filemtime($old) < time() - 3600) {
                try {
                    File::deleteDirectory($old);
                } catch (\Throwable) {
                }
            }
        }
        $dir = storage_path('app/tmp-pdf/'.Str::uuid());
        File::ensureDirectoryExists($dir);
        try {
            file_put_contents("$dir/card.html", $this->html($cards, $title));
            $process = new Process([
                // --headless (not =new): on this server only this mode writes the PDF
                $chrome, '--headless', '--disable-gpu', '--no-sandbox', '--no-first-run', '--no-pdf-header-footer',
                '--user-data-dir='.$dir.'/profile', '--print-to-pdf='.$dir.'/card.pdf', 'file:///'.str_replace('\\', '/', $dir).'/card.html',
            ]);
            $process->setTimeout(60)->run();
            // Chrome may finish writing just after its launcher returned
            for ($i = 0; $i < 60 && (! is_file("$dir/card.pdf") || filesize("$dir/card.pdf") === 0); $i++) {
                usleep(500_000);
                clearstatcache();
            }
            if (! is_file("$dir/card.pdf") || filesize("$dir/card.pdf") === 0) {
                throw new RuntimeException('PDF gagal dibuat: '.trim($process->getErrorOutput() ?: $process->getOutput()));
            }

            usleep(300_000); // let Chrome release the file

            return file_get_contents("$dir/card.pdf");
        } finally {
            // the Chrome profile can stay locked a moment — never fail the download on cleanup
            try {
                File::deleteDirectory($dir);
            } catch (\Throwable) {
            }
        }
    }
}
