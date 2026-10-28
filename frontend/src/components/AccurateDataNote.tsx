import { Link } from 'react-router-dom'
import { CloudCog } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { useSyncStatus } from '@/features/sync/api'
import { cn } from '@/lib/utils'

/** When the Sync Agent pulls Accurate data (Task Scheduler on the server) — one place to keep it right. */
export const SYNC_SCHEDULE = '12:10 & 20:40'

const LABEL: Record<string, { text: string; tone: string }> = {
  SUCCESS: { text: 'berhasil', tone: 'text-emerald-600' },
  PARTIAL: { text: 'sebagian', tone: 'text-amber-600' },
  FAILED: { text: 'gagal', tone: 'text-red-600' },
  RUNNING: { text: 'sedang berjalan', tone: 'text-primary' },
}

/**
 * One-line "this data comes from Accurate, last updated …" note for the
 * Accurate-mirror pages (NPBG, PPB, PO, RI, Stock Opname). Replaces the
 * per-page "Sync Accurate" buttons: syncing lives on one page only.
 */
export function AccurateDataNote({ className }: { className?: string }) {
  const { hasPermission } = useAuth()
  const canView = hasPermission('sync.accurate.view')
  const { data: sync } = useSyncStatus(canView)
  const s = sync ? LABEL[sync.status] : undefined

  return (
    <div className={cn('flex items-start gap-2 text-xs text-muted-foreground', className)}>
      <CloudCog className="mt-px size-3.5 shrink-0" />
      <span className="min-w-0">
        Data dari Accurate (hanya-baca), diperbarui otomatis jam {SYNC_SCHEDULE}
        {sync && (
          <>
            {' · '}terakhir {new Date(sync.finished_at ?? sync.started_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}
            {s && <span className={cn('font-medium', s.tone)}> ({s.text})</span>}
          </>
        )}
        {canView && (
          <>
            {' · '}
            <Link to="/sync/history" className="font-medium text-primary hover:underline">Lihat sync</Link>
          </>
        )}
      </span>
    </div>
  )
}
