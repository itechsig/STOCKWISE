<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Kartu Stock (paper "KARTU STOCK BARANG") of one item from Master Barang, within a project. */
class StockCard extends Model
{
    protected $fillable = ['project_id', 'item_id', 'card_number', 'location_warehouse', 'location_rack', 'person_in_charge', 'director_name', 'created_by', 'updated_by'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** Lines in card order: by date, then as written. */
    public function entries(): HasMany
    {
        return $this->hasMany(StockCardEntry::class)->orderBy('entry_date')->orderBy('id');
    }
}
