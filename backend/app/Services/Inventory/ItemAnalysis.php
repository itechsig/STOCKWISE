<?php

namespace App\Services\Inventory;

/**
 * Result of the STOCKWISE per-item calculation (docs/calculation-engine.md §2).
 */
final class ItemAnalysis
{
    public function __construct(
        public readonly float $sisaStok,
        public readonly float $safetyStock,
        public readonly int $leadTimeDays,
        public readonly float $selisih,
        public readonly string $status,          // AMAN | TIDAK_AMAN | BEP
        public readonly float $deficit,
        public readonly float $priorityScore,
        public readonly string $priorityLevel,   // LOW | MEDIUM | HIGH
        public readonly string $recommendation,
        public readonly float $recommendedQty,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sisa_stok' => $this->sisaStok,
            'safety_stock' => $this->safetyStock,
            'lead_time_days' => $this->leadTimeDays,
            'selisih' => $this->selisih,
            'status' => $this->status,
            'deficit' => $this->deficit,
            'priority_score' => round($this->priorityScore, 2),
            'priority_level' => $this->priorityLevel,
            'recommendation' => $this->recommendation,
            'recommended_qty' => $this->recommendedQty,
        ];
    }
}
