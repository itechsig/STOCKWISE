<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** User name / division / note given to an IP on the Accurate Online page. */
class AccurateIpLabel extends Model
{
    protected $fillable = ['ip', 'user_name', 'division', 'note', 'updated_by'];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
