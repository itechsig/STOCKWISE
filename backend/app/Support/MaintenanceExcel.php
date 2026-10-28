<?php

namespace App\Support;

/**
 * Formulas of the Excel "6. Tracking Maintenance Assets" (sheet "Maintenance Kendaraan"), one Sub SPK row at a time.
 */
class MaintenanceExcel
{
    /**
     * "Status Hasil Pengerjaan":
     *   =IF(AND(NoNPBG<>"",FotoSesudah<>"",TglSelesai<>""),"COMPLETED",IF(NoNPBG<>"","ON-GOING",IF(Permintaan<>"","REQUESTED","")))
     * Foto Sesudah is the link made from "Nama File 2"; Permintaan is the picture of the request.
     */
    public static function status(?string $npbgNo, bool $hasAfter, ?string $finishDate, bool $hasRequest): string
    {
        $has = fn (?string $v) => trim((string) $v) !== '';
        if ($has($npbgNo) && $hasAfter && $has($finishDate)) {
            return ManufacturingExcel::COMPLETED;
        }
        if ($has($npbgNo)) {
            return ManufacturingExcel::ON_GOING;
        }

        return $hasRequest ? ManufacturingExcel::REQUESTED : ManufacturingExcel::INCOMPLETE;
    }

    /** "Helper Nama File Laporan" / "… Pengerjaan": MK.25.VII.001_SUB-01_Before / _After */
    public static function fileName(?string $spk, ?string $subNo, string $which): ?string
    {
        return $spk && $subNo ? str_replace('/', '.', $spk).'_'.$subNo.'_'.($which === 'after' ? 'After' : 'Before') : null;
    }
}
