<?php

namespace Tests\Unit\Accurate;

use App\Services\Accurate\AccurateSyncService;
use PHPUnit\Framework\TestCase;

/** Which accurate_item rows become Master Produk: every product, also with an odd code or no unit. */
class ProductRowRuleTest extends TestCase
{
    public function test_products_come_in_even_without_a_unit_or_with_an_odd_code(): void
    {
        // PUI.0924 has no unit in Accurate, OFN.300 3 digits, "PUI. 0914" a space typed in the code —
        // all three were left out of the Master
        foreach (['AUT.0001', 'PUI.0924', 'OFN.300', 'PUI. 0914'] as $code) {
            $this->assertTrue(AccurateSyncService::isProductRow($code, false), $code);
        }
    }

    public function test_category_nodes_stay_out(): void
    {
        foreach (['AUT', 'AUT.01', 'AUT.01.01', 'AUT.07', 'SSP.27.02'] as $code) {
            $this->assertFalse(AccurateSyncService::isProductRow($code, false), $code);
        }
        // a row with children is a node, whatever its code or unit
        $this->assertFalse(AccurateSyncService::isProductRow('PUI.0001', true));
    }
}
