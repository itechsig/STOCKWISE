<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsedReturnComponentType extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $table = 'used_return_component_types';
}
