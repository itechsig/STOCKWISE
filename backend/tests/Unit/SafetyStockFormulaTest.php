<?php

namespace Tests\Unit;

use App\Services\Inventory\SafetyStockService;
use PHPUnit\Framework\TestCase;

/**
 * Safety Stock / MIN PR formula (per explicit user instruction — replaces an
 * earlier formula that multiplied avg usage by SQRT(lead_time/30), which had
 * no basis):
 *
 *   Lead Time Demand = avg_usage_3m × (lead_time_days / 30)
 *   Safety Stock      = ROUNDUP(2.33 × Lead Time Demand, 0)
 *   MIN PR            = ROUNDUP(Lead Time Demand + Safety Stock, 0)
 *
 * avg_usage_3m — not avg_usage_1m — feeds the formula (see SafetyStockService
 * class docblock for why); this test calls calculate() directly with a raw
 * float, so the scenarios below are valid regardless of which named field a
 * caller sources it from, but the comments here previously said "avg_usage_1m"
 * which no longer matches the service and was corrected.
 *
 * Pure unit tests (no DB) — covers exactly the scenarios requested: no usage,
 * little usage, lots of usage, LT 0/5/30, and null/blank inputs.
 */
class SafetyStockFormulaTest extends TestCase
{
    /** The exact worked example given in the brief: avg=0.5, LT=5 -> demand=0.08333 -> SS=1 -> MIN PR=2. */
    public function test_worked_example_from_the_brief(): void
    {
        $result = SafetyStockService::calculate(0.5, 5);

        $this->assertEqualsWithDelta(0.08333, $result['lead_time_demand'], 0.0001);
        $this->assertSame(1.0, $result['safety_stock']);
        $this->assertSame(2.0, $result['min_pr']);
    }

    public function test_item_with_no_usage_at_all(): void
    {
        // "barang tanpa pengeluaran" — zero usage must never be inflated into
        // a fabricated Safety Stock, regardless of Lead Time.
        foreach ([0, 5, 10, 30] as $leadTime) {
            $result = SafetyStockService::calculate(0.0, $leadTime);

            $this->assertSame(0.0, $result['safety_stock'], "LT={$leadTime}");
            $this->assertSame(0.0, $result['min_pr'], "LT={$leadTime}");
        }
    }

    public function test_item_with_little_usage_lead_time_5_days(): void
    {
        // avg 2 units/month, LT 5 days: demand = 2*(5/30) = 0.3333
        $result = SafetyStockService::calculate(2.0, 5);

        $this->assertEqualsWithDelta(0.3333, $result['lead_time_demand'], 0.001);
        $this->assertSame(1.0, $result['safety_stock']); // ROUNDUP(2.33*0.3333=0.7767) = 1
        $this->assertSame(2.0, $result['min_pr']);        // ROUNDUP(0.3333+1=1.3333) = 2
    }

    public function test_item_with_little_usage_lead_time_10_days(): void
    {
        // avg 2 units/month, LT 10 days: demand = 2*(10/30) = 0.6667
        $result = SafetyStockService::calculate(2.0, 10);

        $this->assertEqualsWithDelta(0.6667, $result['lead_time_demand'], 0.001);
        $this->assertSame(2.0, $result['safety_stock']); // ROUNDUP(2.33*0.6667=1.5533) = 2
        $this->assertSame(3.0, $result['min_pr']);        // ROUNDUP(0.6667+2=2.6667) = 3
    }

    public function test_item_with_lots_of_usage_lead_time_30_days(): void
    {
        // avg 500 units/month (high-turnover item), LT 30 days: demand = 500*(30/30) = 500
        $result = SafetyStockService::calculate(500.0, 30);

        $this->assertSame(500.0, $result['lead_time_demand']);
        $this->assertSame(1165.0, $result['safety_stock']); // ROUNDUP(2.33*500=1165) = 1165
        $this->assertSame(1665.0, $result['min_pr']);        // ROUNDUP(500+1165=1665) = 1665
    }

    public function test_lead_time_zero_means_no_buffer_needed_regardless_of_usage(): void
    {
        foreach ([0.0, 5.0, 100.0] as $avg) {
            $result = SafetyStockService::calculate($avg, 0);

            $this->assertSame(0.0, $result['lead_time_demand'], "avg={$avg}");
            $this->assertSame(0.0, $result['safety_stock'], "avg={$avg}");
            $this->assertSame(0.0, $result['min_pr'], "avg={$avg}");
        }
    }

    public function test_lead_time_30_days_equals_one_full_month_of_demand(): void
    {
        // LT/30 == 1, so lead_time_demand == avg_usage_1m exactly.
        $result = SafetyStockService::calculate(12.0, 30);

        $this->assertSame(12.0, $result['lead_time_demand']);
    }

    public function test_null_usage_is_treated_as_zero_not_guessed(): void
    {
        $result = SafetyStockService::calculate(null, 15);

        $this->assertSame(0.0, $result['lead_time_demand']);
        $this->assertSame(0.0, $result['safety_stock']);
        $this->assertSame(0.0, $result['min_pr']);
    }

    public function test_null_lead_time_is_treated_as_zero_not_guessed(): void
    {
        $result = SafetyStockService::calculate(20.0, null);

        $this->assertSame(0.0, $result['lead_time_demand']);
        $this->assertSame(0.0, $result['safety_stock']);
        $this->assertSame(0.0, $result['min_pr']);
    }

    public function test_both_null_is_all_zero(): void
    {
        $result = SafetyStockService::calculate(null, null);

        $this->assertSame(0.0, $result['safety_stock']);
        $this->assertSame(0.0, $result['min_pr']);
    }

    /** Same formula for every item — no branching on category/type anywhere in calculate(). */
    public function test_formula_has_no_category_or_item_type_branching(): void
    {
        $reflection = new \ReflectionMethod(SafetyStockService::class, 'calculate');
        $lines = file($reflection->getFileName());
        $body = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));

        $this->assertStringNotContainsStringIgnoringCase('category', $body);
        $this->assertStringNotContainsStringIgnoringCase('item_type', $body);
    }

    public function test_result_is_always_a_whole_number_roundup(): void
    {
        // Non-integer inputs chosen to land mid-fraction, not on a convenient boundary.
        foreach ([[1.7, 5], [3.1, 10], [0.9, 30], [17.3, 5]] as [$avg, $lt]) {
            $result = SafetyStockService::calculate($avg, $lt);

            $this->assertSame((float) (int) $result['safety_stock'], $result['safety_stock'], "avg={$avg} lt={$lt}");
            $this->assertSame((float) (int) $result['min_pr'], $result['min_pr'], "avg={$avg} lt={$lt}");
        }
    }

    public function test_safety_stock_and_min_pr_never_negative(): void
    {
        foreach ([[0, 0], [0, 30], [100, 0]] as [$avg, $lt]) {
            $result = SafetyStockService::calculate($avg, $lt);

            $this->assertGreaterThanOrEqual(0.0, $result['safety_stock']);
            $this->assertGreaterThanOrEqual(0.0, $result['min_pr']);
        }
    }

    public function test_min_pr_always_at_least_safety_stock_when_there_is_demand(): void
    {
        // MIN PR = demand + safety_stock, and demand >= 0, so MIN PR >= safety_stock always.
        foreach ([[5, 5], [5, 10], [5, 30], [50, 15]] as [$avg, $lt]) {
            $result = SafetyStockService::calculate($avg, $lt);

            $this->assertGreaterThanOrEqual($result['safety_stock'], $result['min_pr'], "avg={$avg} lt={$lt}");
        }
    }
}
