<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Important Request: a work instruction from a superior (the creator, who also reviews the results)
 * to one or more people. INDIVIDUAL: every recipient does and submits their own part, the request is
 * done when all parts are; COLLECTIVE: the recipients deliver one result together.
 */
class ImportantRequest extends Model
{
    protected $guarded = ['id'];

    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];

    public const MODES = ['INDIVIDUAL', 'COLLECTIVE'];

    /** request statuses that are over (no deadline reminders, not overdue) */
    public const CLOSED = ['DRAFT', 'SELESAI', 'DIBATALKAN'];

    protected function casts(): array
    {
        return [
            'deadline' => 'datetime', 'requires_approval' => 'boolean', 'sent_at' => 'datetime', 'completed_at' => 'datetime',
            'cancelled_at' => 'datetime', 'reminded_soon_at' => 'datetime', 'reminded_overdue_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignees(): HasMany
    {
        return $this->hasMany(ImportantRequestAssignee::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ImportantRequestComment::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ImportantRequestSubmission::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ImportantRequestAttachment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ImportantRequestActivity::class);
    }

    /** Overdue: past its deadline and not over — an indicator, not a status. */
    public function isOverdue(): bool
    {
        return $this->deadline !== null && ! in_array($this->status, self::CLOSED, true) && $this->deadline->isPast();
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->whereNotIn('status', self::CLOSED)->whereNotNull('deadline')->where('deadline', '<', now());
    }

    /** What this user may see: theirs (made / assigned), everything with view_all; drafts only their maker. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $q->where(function ($w) use ($user) {
            $w->where('created_by', $user->id)
                ->orWhere(fn ($x) => $x->where('status', '!=', 'DRAFT')->whereHas('assignees', fn ($a) => $a->where('user_id', $user->id)));
            if ($user->hasPermission('important_request.view_all')) {
                $w->orWhere('status', '!=', 'DRAFT');
            }
        });
    }
}
