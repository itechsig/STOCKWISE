<?php

namespace App\Services;

use App\Models\Item;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Rekomendasi stok" for a free-text Request Barang line: ranks the active
 * master items by how well their description matches what the requester
 * typed — no outside service, all in PHP:
 *
 *   1. normalize  upper-case, punctuation out (Item::normalize), filler words
 *                 dropped (UNTUK, MOHON, BUAH …), common English/Indonesian
 *                 pairs folded (BOLT→BAUT, CABLE→KABEL, HOSE→SELANG …);
 *   2. weigh      every query word by its IDF over the master list — a rare
 *                 word ("GERINDA") counts far more than a common one ("SS");
 *   3. fuzzy      a query word also matches a master word it is a prefix of,
 *                 or one 1–2 typos away (Levenshtein), at a lower weight;
 *   4. rank       score = Σ idf(word)·best match / Σ idf(word), plus a small
 *                 tie-break for items whose own words are mostly covered.
 *
 * The master index (vocabulary, document frequencies, per-item word sets) is
 * cached for 10 minutes; one recommendation scores all items in memory.
 */
class ItemRecommender
{
    private const STOPWORDS = ['UNTUK', 'UTK', 'DAN', 'ATAU', 'YANG', 'YG', 'DENGAN', 'DGN', 'DI', 'KE', 'DARI', 'BUAH', 'BH', 'PCS',
        'UNIT', 'MOHON', 'MINTA', 'TOLONG', 'BARANG', 'UKURAN', 'UK', 'SAMA', 'BUTUH', 'PERLU', 'KEBUTUHAN', 'PAKAI', 'BARU', 'THE', 'FOR', 'AND', 'OF'];

    /** requester word => master word */
    private const SYNONYMS = [
        'BOLT' => 'BAUT', 'NUT' => 'MUR', 'WASHER' => 'RING', 'CABLE' => 'KABEL', 'HOSE' => 'SELANG', 'TIRE' => 'BAN', 'TYRE' => 'BAN',
        'OIL' => 'OLI', 'LAMP' => 'LAMPU', 'GLOVE' => 'SARUNG', 'GLOVES' => 'SARUNG', 'PAINT' => 'CAT', 'BRUSH' => 'KUAS', 'TAPE' => 'ISOLASI',
        'GRINDA' => 'GERINDA', 'GURINDA' => 'GERINDA', 'GERENDA' => 'GERINDA', 'PIPE' => 'PIPA', 'VALVE' => 'VALVE', 'KRAN' => 'KRAN',
        'BATERAI' => 'BATERAI', 'BATRE' => 'BATERAI', 'BATERE' => 'BATERAI', 'AKI' => 'ACCU', 'KERTAS' => 'KERTAS', 'PULPEN' => 'BALLPOINT',
        'BOLPEN' => 'BALLPOINT', 'HIDROLIS' => 'HIDROLIK', 'HYDRAULIC' => 'HIDROLIK', 'COKLAT' => 'COKELAT', 'BROWN' => 'COKELAT', 'BLACK' => 'HITAM', 'BOLPOIN' => 'BALLPOINT', 'PEN' => 'BALLPOINT', 'SOLAR' => 'DEXLITE', 'BENSIN' => 'PERTALITE',
    ];

    /** @return list<array{id:int,code:string,description:string,unit:?string,available:?float,stock_known:bool,score:float}> */
    public function recommend(string $text, int $limit = 5): array
    {
        $words = $this->words($text);
        if ($words === []) {
            return [];
        }
        $index = $this->index();
        $n = max(1, count($index['items']));

        // per query word: how well each vocabulary word matches it (1 exact, < 1 prefix / typo)
        $matches = [];
        $weights = [];
        foreach ($words as $i => $q) {
            $sims = [];
            foreach ($index['vocab'] as $v => $df) {
                $sim = $this->similarity($q, $v);
                if ($sim > 0) {
                    $sims[$v] = $sim;
                }
            }
            // IDF — rare words weigh more; a word not in the master list takes the rarest close match's
            $df = $index['vocab'][$q] ?? ($sims === [] ? 1 : min(array_map(fn ($v) => $index['vocab'][$v], array_keys($sims))));
            $weights[$q] = log(1 + $n / max(1, $df));
            $matches[$q] = $sims;
            if ($sims === []) {
                unset($weights[$q], $words[$i]); // a word no master item has ("PANJANG") can't help pick one — don't let it lower every score
            }
        }
        $words = array_values($words);
        if ($words === []) {
            return [];
        }
        $total = array_sum($weights) ?: 1;
        // master words that clearly answer some query word (for the coverage tie-break)
        $answered = [];
        foreach ($matches as $sims) {
            foreach ($sims as $v => $sim) {
                if ($sim >= 0.75) {
                    $answered[$v] = true;
                }
            }
        }

        $scores = [];
        foreach ($index['items'] as $id => $itemWords) {
            $hit = 0.0;
            foreach ($words as $q) {
                $best = 0.0;
                foreach ($itemWords as $w) {
                    $s = $matches[$q][$w] ?? 0.0;
                    if ($s > $best) {
                        $best = $s;
                        if ($best >= 1.0) {
                            break;
                        }
                    }
                }
                $hit += $weights[$q] * $best;
            }
            if ($hit <= 0) {
                continue;
            }
            // tie-break: prefer items whose own words are mostly what was asked (short, specific names)
            $covered = count(array_filter($itemWords, fn ($w) => isset($answered[$w])));
            $scores[$id] = $hit / $total + 0.05 * $covered / max(1, count($itemWords));
        }
        if ($scores === []) {
            return [];
        }
        arsort($scores);
        // re-rank the best few with what only the item row knows: scrap rows ((BEKAS)/(RUSAK)/(BUANG)/(REFURBISHED))
        // go down, items actually in stock win a tie
        $pool = array_slice($scores, 0, max(40, $limit * 8), true);
        $items = Item::query()->with(['unit:id,code'])->whereIn('id', array_keys($pool))->get()->keyBy('id');
        // live stock (warehouse rows), the same the Request Barang page shows
        $stock = \App\Services\ItemRequestFulfillmentService::availableMany(array_keys($pool));
        foreach ($pool as $id => $score) {
            $item = $items[$id] ?? null;
            if (! $item) {
                unset($pool[$id]);
                continue;
            }
            if (preg_match('/^\s*\((BEKAS|RUSAK|BUANG|REFURBISHED)\)/i', $item->description)) {
                $score *= 0.85;
            }
            if (($stock[$id] ?? 0) > 0) {
                $score += 0.02;
            }
            $pool[$id] = $score;
        }
        arsort($pool);
        // best first; beyond the first 5 only real matches (a long list of near-misses helps nobody)
        $top = [];
        foreach ($pool as $id => $score) {
            if (count($top) >= $limit || (count($top) >= 5 && $score < 0.3)) {
                break;
            }
            $top[$id] = $score;
        }

        return collect($top)->map(fn ($score, $id) => [
            'id' => (int) $id,
            'code' => $items[$id]->code,
            'description' => $items[$id]->description,
            'unit' => $items[$id]->unit?->code,
            'available' => $stock[$id] ?? null,
            'stock_known' => array_key_exists($id, $stock),
            'score' => (int) round(min(1, $score) * 100),
        ])->values()->all();
    }

    /** @return list<string> normalized, filler-free, synonym-folded words */
    public function words(string $text): array
    {
        $text = mb_strtoupper($text);
        // sizes the way the master list writes them: 6cm -> 60MM, 14 mm -> 14MM, 12 watt -> 12W; "inch" is the quote mark (dropped)
        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*CM\b/u', fn ($m) => rtrim(rtrim(number_format((float) str_replace(',', '.', $m[1]) * 10, 1, '.', ''), '0'), '.').'MM', $text);
        $text = preg_replace(['/(\d)\s+MM\b/u', '/(\d+)\s*(?:WATT|WT)\b/u', '/\b(?:INCH|INCHI|INC|IN)\b/u'], ['$1MM', '$1W', ' '], $text);
        $words = [];
        foreach ($this->tokens($text) as $w) {
            if ($w === '' || in_array($w, self::STOPWORDS, true) || (strlen($w) < 2 && ! ctype_digit($w))) {
                continue;
            }
            $words[] = self::SYNONYMS[$w] ?? $w;
        }

        return array_values(array_unique($words));
    }

    /** Normalized words, a decimal kept whole ("7.50" -> "7P50", not "7" + "50") — same for the request and the master list. */
    private function tokens(string $text): array
    {
        return explode(' ', Item::normalize(preg_replace('/(\d)[.,](\d)/u', '$1P$2', $text)));
    }

    /** 1 = same word; a prefix (≥3 letters) or 1–2 typos away scores lower; 0 = unrelated. */
    private function similarity(string $q, string $v): float
    {
        if ($q === $v) {
            return 1.0;
        }
        if (preg_match('/\d/', $q) || preg_match('/\d/', $v)) {
            return 0.0; // sizes/numbers (4, 14MM, 12W) must match exactly — 14MM is not a typo of 4MM
        }
        $lq = strlen($q);
        $lv = strlen($v);
        if ($lq >= 3 && str_starts_with($v, $q)) {
            return 0.85 * $lq / $lv + 0.1; // "GERIN" -> "GERINDA"
        }
        if ($lv >= 3 && str_starts_with($q, $v)) {
            return 0.75 * $lv / $lq;
        }
        if ($lq < 4 || abs($lq - $lv) > 2) {
            return 0.0;
        }
        $d = levenshtein($q, $v);

        return match (true) {
            $d === 1 => 0.8,
            $d === 2 && $lq >= 7 => 0.6,
            default => 0.0,
        };
    }

    /** @return array{vocab: array<string,int>, items: array<int, list<string>>} */
    private function index(): array
    {
        return Cache::remember('item_recommender_index_v2', 600, function () {
            $items = [];
            $vocab = [];
            DB::table('items')->where('is_active', true)->orderBy('id')->select('id', 'description')
                ->chunk(2000, function ($rows) use (&$items, &$vocab) {
                    foreach ($rows as $r) {
                        $words = array_values(array_unique(array_filter($this->tokens((string) $r->description), fn ($w) => $w !== '')));
                        $items[$r->id] = $words;
                        foreach ($words as $w) {
                            $vocab[$w] = ($vocab[$w] ?? 0) + 1;
                        }
                    }
                });

            return ['vocab' => $vocab, 'items' => $items];
        });
    }
}
