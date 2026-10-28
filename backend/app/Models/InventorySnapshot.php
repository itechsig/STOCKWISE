<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventorySnapshot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'actual' => 'float', 'reserved' => 'float', 'available' => 'float',
            'stock_known' => 'boolean',
            'safety_stock' => 'float', 'selisih' => 'float', 'deficit' => 'float',
            'priority_score' => 'float', 'recommended_qty' => 'float',
            'lead_time_days' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(InventoryAnalysisRun::class, 'analysis_run_id');
    }
}
