<?php

namespace App\Support;

/**
 * Pictures placed inside cells by WPS Spreadsheets — the cell holds
 * =DISPIMG("ID_…",1) and the picture lives in xl/cellimages.xml → rels → xl/media.
 * PhpSpreadsheet does not read them; this does, straight from the .xlsx zip.
 */
class WpsCellImages
{
    /** @var array<string, string> ID_… => zip entry */
    private array $map = [];

    private ?\ZipArchive $zip = null;

    public function __construct(string $xlsx)
    {
        $zip = new \ZipArchive;
        if ($zip->open($xlsx) !== true) {
            return;
        }
        $this->zip = $zip;
        $xml = $zip->getFromName('xl/cellimages.xml');
        $rels = $zip->getFromName('xl/_rels/cellimages.xml.rels');
        if ($xml === false || $rels === false) {
            return;
        }
        preg_match_all('/Id="([^"]+)"[^>]*Target="([^"]+)"|Target="([^"]+)"[^>]*Id="([^"]+)"/', $rels, $m, PREG_SET_ORDER);
        $targets = [];
        foreach ($m as $r) {
            [$id, $target] = $r[1] !== '' ? [$r[1], $r[2]] : [$r[4], $r[3]];
            $targets[$id] = 'xl/'.ltrim(str_replace('../', '', $target), '/');
        }
        preg_match_all('/<etc:cellImage>.*?name="(ID_[0-9A-Fa-f]+)".*?r:embed="([^"]+)".*?<\/etc:cellImage>/s', $xml, $pics, PREG_SET_ORDER);
        foreach ($pics as $p) {
            if (isset($targets[$p[2]])) {
                $this->map[strtoupper($p[1])] = $targets[$p[2]];
            }
        }
    }

    /** The ID in a cell formula =DISPIMG("ID_…",1), or null. */
    public static function idOf(mixed $cellValue): ?string
    {
        return is_string($cellValue) && preg_match('/DISPIMG\(\s*"(ID_[0-9A-Fa-f]+)"/i', $cellValue, $m) ? strtoupper($m[1]) : null;
    }

    public function count(): int
    {
        return count($this->map);
    }

    /** @return array{bytes:string, ext:string}|null */
    public function get(?string $id): ?array
    {
        $entry = $id ? ($this->map[strtoupper($id)] ?? null) : null;
        if (! $entry || ! $this->zip) {
            return null;
        }
        $bytes = $this->zip->getFromName($entry);

        return $bytes === false ? null : ['bytes' => $bytes, 'ext' => strtolower(pathinfo($entry, PATHINFO_EXTENSION)) ?: 'jpg'];
    }
}
