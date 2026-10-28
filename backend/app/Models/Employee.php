<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Employee extends Model
{
    protected $fillable = [
        'name', 'name_normalized', 'phone', 'position', 'department_id', 'site_id', 'user_id',
        'role_hint', 'is_active', 'needs_review', 'source',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Employee $employee) {
            $employee->name_normalized = self::normalize($employee->name);
        });
    }

    public static function normalize(?string $name): string
    {
        return Str::of($name ?? '')->squish()->upper()->value();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
