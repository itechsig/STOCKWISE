<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/notifications — the bell dropdown (frontend NotificationBell.tsx).
 * Every authenticated user reads only their own notifications (Laravel's
 * Notifiable/DatabaseNotification scopes to $request->user() automatically).
 * Gated by `notification.view_own`, held by every role — see Rbac.php.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 50);

        $page = $request->user()->notifications()->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => $this->row($n)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $n = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $n->markAsRead();

        return response()->json(['data' => $this->row($n->fresh())]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'Semua notifikasi ditandai sudah dibaca.']);
    }

    private function row($n): array
    {
        return [
            'id' => $n->id,
            'category' => $n->data['category'] ?? null,
            'level' => $n->data['level'] ?? 'info',
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? '',
            'link' => $n->data['link'] ?? null,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
