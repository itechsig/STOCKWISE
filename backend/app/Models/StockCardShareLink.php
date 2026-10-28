<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A link that opens the Kartu Stock of one project without an account (VIEW / EDIT / FULL). */
class StockCardShareLink extends Model
{
    public const ACCESS = ['VIEW', 'EDIT', 'FULL'];

    protected $fillable = ['project_id', 'token', 'access', 'label', 'created_by', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** EDIT: add / change lines, header and attachments. */
    public function canEdit(): bool
    {
        return in_array($this->access, ['EDIT', 'FULL'], true);
    }

    /** FULL: also delete, and make new cards. */
    public function isFull(): bool
    {
        return $this->access === 'FULL';
    }
}
