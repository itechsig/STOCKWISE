<?php

namespace Tests\Unit;

use App\Services\Inventory\InventoryAnalyzer;
use App\Services\Inventory\StockwiseEngine;
use PHPUnit\Framework\TestCase;

/**
 * docs/calculation-engine.md §7 — TC-INV-001..009.
 */
class StockwiseEngineTest extends TestCase
{
    private StockwiseEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new StockwiseEngine;
    }

    private function analyze(float $sisa, float $ss, int $lt, float $median = 0, float $threshold = 14)
    {
        return $this->engine->analyze($sisa, $ss, $lt, $median, $threshold, 'PCS');
    }

    public function test_tc_inv_001_aman(): void
    {
        $a = $this->analyze(100, 50, 7);

        $this->assertSame(50.0, $a->selisih);
        $this->assertSame('AMAN', $a->status);
        $this->assertSame(0.0, $a->deficit);
        $this->assertSame(0.0, $a->priorityScore);
        $this->assertSame('LOW', $a->priorityLevel);
    }

    public function test_tc_inv_002_tidak_aman_score(): void
    {
        $a = $this->analyze(40, 50, 7);

        $this->assertSame(-10.0, $a->selisih);
        $this->assertSame('TIDAK_AMAN', $a->status);
        $this->assertSame(10.0, $a->deficit);
        // (10 * 2.0) + (7 * 1.0) = 27
        $this->assertSame(27.0, $a->priorityScore);
    }

    public function test_tc_inv_003_bep(): void
    {
        $a = $this->analyze(0, 0, 5);

        $this->assertSame('BEP', $a->status);
        $this->assertSame(0.0, $a->priorityScore);
        $this->assertSame('LOW', $a->priorityLevel);
    }

    public function test_tc_inv_007_priority_level(): void
    {
        // dataset: TIDAK_AMAN deficits [2,4,10,10,50] -> median 10 ; threshold 20
        $median = 10;
        $threshold = 20;

        // deficit 10 >= median 10 -> HIGH
        $this->assertSame('HIGH', $this->engine->analyze(0, 10, 5, $median, $threshold)->priorityLevel);
        // deficit 4 < median, but LT 25 >= threshold 20 -> HIGH
        $this->assertSame('HIGH', $this->engine->analyze(0, 4, 25, $median, $threshold)->priorityLevel);
        // deficit 2 < median, LT 5 < threshold -> MEDIUM
        $this->assertSame('MEDIUM', $this->engine->analyze(0, 2, 5, $median, $threshold)->priorityLevel);
    }

    public function test_tc_inv_008_percentile_75(): void
    {
        $analyzer = new InventoryAnalyzer($this->engine);

        $this->assertSame(14.0, $analyzer->percentile([3, 5, 7, 14, 30], 75));
    }

    public function test_median_helper(): void
    {
        $analyzer = new InventoryAnalyzer($this->engine);

        $this->assertSame(10.0, $analyzer->median([2, 4, 10, 10, 50]));
        $this->assertSame(7.0, $analyzer->median([4, 10]));
    }

    public function test_recommendation_text(): void
    {
        $this->assertStringContainsString('Tidak perlu tindakan', $this->analyze(100, 50, 7)->recommendation);
        $this->assertStringContainsString('PRIORITAS TINGGI', $this->analyze(0, 100, 30)->recommendation);
        $this->assertStringContainsString('PPB', $this->analyze(5, 10, 3)->recommendation);
    }

    /** Property: non-negative inputs never produce negative deficit/score; AMAN => score 0. */
    public function test_invariants(): void
    {
        foreach ([[0, 0], [10, 5], [5, 10], [100, 1], [1, 100], [50, 50]] as [$sisa, $ss]) {
            $a = $this->analyze($sisa, $ss, 10);
            $this->assertGreaterThanOrEqual(0, $a->deficit);
            $this->assertGreaterThanOrEqual(0, $a->priorityScore);
            if ($a->status === 'AMAN' || $a->status === 'BEP') {
                $this->assertSame(0.0, $a->priorityScore);
            }
        }
    }
}
