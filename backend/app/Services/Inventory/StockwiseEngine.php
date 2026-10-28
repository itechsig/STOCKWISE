<?php

namespace App\Services\Inventory;

/**
 * STOCKWISE calculation engine — pure, no I/O (docs/calculation-engine.md).
 *
 * "Sisa Stok" = Available Stock (actual - reserved), assumption A1.
 * Dataset-level parameters (median deficit, lead-time threshold) are computed by
 * InventoryAnalyzer and passed in.
 */
class StockwiseEngine
{
    private const EPS = 1e-6;

    public function analyze(
        float $sisaStok,
        float $safetyStock,
        int $leadTimeDays,
        float $medianDeficitTidakAman,
        float $leadTimeThreshold,
        ?string $uom = null,
    ): ItemAnalysis {
        $selisih = $sisaStok - $safetyStock;

        $status = $this->status($sisaStok, $safetyStock, $selisih);
        $deficit = max($safetyStock - $sisaStok, 0.0);

        $priorityScore = $status === 'TIDAK_AMAN'
            ? ($deficit * 2.0) + ($leadTimeDays * 1.0)
            : 0.0;

        $priorityLevel = $this->priorityLevel(
            $status, $deficit, $leadTimeDays, $medianDeficitTidakAman, $leadTimeThreshold
        );

        [$recommendation, $recommendedQty] = $this->recommendation(
            $status, $priorityLevel, $deficit, $leadTimeDays, $uom
        );

        return new ItemAnalysis(
            sisaStok: $sisaStok,
            safetyStock: $safetyStock,
            leadTimeDays: $leadTimeDays,
            selisih: $selisih,
            status: $status,
            deficit: $deficit,
            priorityScore: $priorityScore,
            priorityLevel: $priorityLevel,
            recommendation: $recommendation,
            recommendedQty: $recommendedQty,
        );
    }

    private function status(float $sisaStok, float $safetyStock, float $selisih): string
    {
        if (abs($sisaStok) < self::EPS && abs($safetyStock) < self::EPS) {
            return 'BEP';
        }

        return $selisih >= -self::EPS ? 'AMAN' : 'TIDAK_AMAN';
    }

    private function priorityLevel(
        string $status,
        float $deficit,
        int $leadTimeDays,
        float $medianDeficit,
        float $leadTimeThreshold,
    ): string {
        if ($status !== 'TIDAK_AMAN') {
            return 'LOW';
        }

        $isHigh = ($deficit >= $medianDeficit - self::EPS)
            || ($leadTimeDays >= $leadTimeThreshold - self::EPS);

        return $isHigh ? 'HIGH' : 'MEDIUM';
    }

    /** @return array{0: string, 1: float} */
    private function recommendation(
        string $status,
        string $priorityLevel,
        float $deficit,
        int $leadTimeDays,
        ?string $uom,
    ): array {
        $u = $uom ? " {$uom}" : '';
        $qty = ceil($deficit);

        return match (true) {
            $status === 'AMAN' => ['Stok aman. Tidak perlu tindakan.', 0.0],
            $status === 'BEP' => [
                'Stok habis (BEP) — tidak perlu dibeli: tidak ada pemakaian, safety stock nol.',
                0.0,
            ],
            $priorityLevel === 'HIGH' => [
                "PRIORITAS TINGGI — buat PPB segera sejumlah {$qty}{$u}. Lead time {$leadTimeDays} hari.",
                (float) $qty,
            ],
            default => [
                "Buat PPB sejumlah {$qty}{$u} dalam waktu dekat.",
                (float) $qty,
            ],
        };
    }
}
