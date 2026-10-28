import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

export type NotificationLevel = 'info' | 'success' | 'warning' | 'danger'

export interface NotificationRow {
  id: string
  category: string
  level: NotificationLevel
  title: string
  body: string
  link: string | null
  read_at: string | null
  created_at: string
}

/**
 * Near-realtime by polling: `php artisan serve` is single-threaded, so a
 * held-open websocket/SSE connection would block every other request.
 * The unread-count call is one cheap COUNT query.
 */
const POLL_MS = 10_000

export function useNotifications(enabled = true) {
  return useQuery({
    queryKey: ['notifications', 'list'],
    queryFn: async () => (await api.get<Paginated<NotificationRow>>('/api/notifications')).data,
    // opened dropdown always shows the latest list, and keeps it fresh while open
    staleTime: 0,
    refetchOnMount: 'always',
    refetchInterval: POLL_MS,
    enabled,
  })
}

export function useUnreadNotificationCount(enabled = true) {
  return useQuery({
    queryKey: ['notifications', 'unread-count'],
    queryFn: async () => (await api.get<{ data: { count: number } }>('/api/notifications/unread-count')).data.data.count,
    staleTime: 0,
    refetchInterval: POLL_MS,
    // catch up immediately when the user comes back to the tab / reconnects
    refetchOnWindowFocus: 'always',
    refetchOnReconnect: 'always',
    enabled,
  })
}

export function useMarkNotificationRead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: string) => (await api.post<{ data: NotificationRow }>(`/api/notifications/${id}/read`)).data.data,
    meta: { successMessage: false },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })
}

export function useMarkAllNotificationsRead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => (await api.post('/api/notifications/read-all')).data,
    meta: { successMessage: false },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })
}
