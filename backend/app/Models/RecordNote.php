<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A user's "Catatan" on one row of a data table (entity + row key). */
class RecordNote extends Model
{
    protected $guarded = ['id'];

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
