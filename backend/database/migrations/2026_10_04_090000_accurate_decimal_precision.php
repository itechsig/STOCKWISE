<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accurate keeps quantities, prices and ratios as NUMERIC(18,4); these copies
 * had 2 decimals, so e.g. a price of 119,369.3694 or a stock of 46.656 lost its
 * last digits (user: "database sama persis seperti Accurate", 2026-10-04).
 * Widening only — every existing value fits and stays as it is.
 */
return new class extends Migration
{
    /** table => [column => nullable, default] */
    private const COLUMNS = [
        'npbg' => ['kuantitas' => [true, null]],
        'ppb' => ['kuantitas' => [true, null], 'qty_dipesan' => [true, null], 'qty_diterima' => [true, null]],
        'ri' => ['kuantitas' => [true, null], 'harga_satuan' => [true, null]],
        'purchase_order_items' => ['qty' => [false, null], 'qty_received' => [false, '0'], 'unit_price' => [false, '0']],
        'items' => ['accurate_qty_onhand' => [true, null], 'accurate_qty_onorder' => [true, null]],
    ];

    public function up(): void
    {
        $this->resize(18, 4);
    }

    public function down(): void
    {
        $this->resize(14, 2, priceTotal: 16);
    }

    private function resize(int $total, int $places, ?int $priceTotal = null): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns, $total, $places, $priceTotal) {
                foreach ($columns as $column => [$nullable, $default]) {
                    $size = $priceTotal && in_array($column, ['harga_satuan', 'unit_price'], true) ? $priceTotal : $total;
                    $c = $t->decimal($column, $size, $places);
                    $nullable ? $c->nullable() : $c->nullable(false);
                    if ($default !== null) {
                        $c->default($default);
                    }
                    $c->change();
                }
            });
        }

        // difference is generated from the two quantities — keep its expression
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_opname_items
                MODIFY system_qty DECIMAL({$total},{$places}) NOT NULL DEFAULT 0,
                MODIFY physical_qty DECIMAL({$total},{$places}) NULL,
                MODIFY difference DECIMAL({$total},{$places}) GENERATED ALWAYS AS (physical_qty - system_qty) STORED");
        }
    }
};
