<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\StockwiseAlert;
use Illuminate\Support\Facades\Notification;

/**
 * Fan-out helper for StockwiseAlert — centralizes the "every user holding
 * permission X" query so trigger sites (AccurateSyncService, RequestService,
 * StockOpnameService, ...) don't each repeat it.
 */
class NotificationDispatcher
{
    /**
     * Notify every user holding any of the given permission slugs.
     *
     * @param  string|array<int, string>  $permissions
     */
    public static function toPermission(
        string|array $permissions,
        string $category,
        string $level,
        string $title,
        string $body,
        ?string $link = null,
        ?int $exceptUserId = null,
    ): void {
        $slugs = (array) $permissions;

        $users = User::query()
            ->whereHas('roles.permissions', fn ($q) => $q->whereIn('slug', $slugs))
            ->when($exceptUserId, fn ($q) => $q->where('users.id', '!=', $exceptUserId))
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new StockwiseAlert($category, $level, $title, $body, $link));
    }

    /** Notify a single, specific user (e.g. "your request was reserved"). */
    public static function toUser(
        ?int $userId,
        string $category,
        string $level,
        string $title,
        string $body,
        ?string $link = null,
    ): void {
        if (! $userId) {
            return;
        }

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        $user->notify(new StockwiseAlert($category, $level, $title, $body, $link));
    }
}
