<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An evidence photo of a tracking row (entity + id) in one slot — see TrackingPhotoService. */
class TrackingPhoto extends Model
{
    protected $guarded = ['id'];
}
