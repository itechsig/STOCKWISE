<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryAnalysisRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lead_time_threshold' => 'float',
            'median_deficit' => 'float',
            'item_count' => 'integer',
            'tidak_aman_count' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(InventorySnapshot::class, 'analysis_run_id');
    }
}
