<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Single generic notification class for every in-app alert (sync status,
 * request handoffs, stock opname, purchase proposal, NPBG verification,
 * safety stock conflicts, ...). One flexible class instead of one per event
 * type — the "category"/"level" fields are what the frontend bell groups
 * and colors by, see frontend/src/components/NotificationBell.tsx.
 */
class StockwiseAlert extends Notification
{
    public function __construct(
        public readonly string $category,
        public readonly string $level,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $link = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'level' => $this->level,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
        ];
    }
}
