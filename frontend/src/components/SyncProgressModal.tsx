import { useEffect, useRef, useState } from 'react'
import { useIsMutating, useMutationState } from '@tanstack/react-query'
import { CheckCircle2, Circle, Loader2 } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import {
  SYNC_STEP_LABELS,
  SYNC_STEP_ORDER,
  TRIGGER_SYNC_MUTATION_KEY,
  announceSyncResult,
  useSyncStatus,
} from '@/features/sync/api'
import { Modal } from '@/components/ui/modal'

/** Ticks once a second while `active`, from when it first became active. */
function useElapsedSeconds(active: boolean): number {
  const [elapsed, setElapsed] = useState(0)

  useEffect(() => {
    if (!active) {
      setElapsed(0)
      return
    }
    const startedAt = Date.now()
    const id = setInterval(() => setElapsed(Math.floor((Date.now() - startedAt) / 1000)), 1000)
    return () => clearInterval(id)
  }, [active])

  return elapsed
}

/**
 * Global "sync is running" indicator for the user who pressed a "Sync
 * Accurate" button (any page — observed via useIsMutating). Since 2026-09-26
 * the server processes the sync in a background process, so the web app
 * stays usable and this modal can follow real progress from
 * GET /api/sync/status, then announce the result when the batch finishes.
 * Scheduled Sync Agent runs never open it (nobody pressed anything).
 */
export function SyncProgressModal() {
  const { hasPermission } = useAuth()
  const canView = hasPermission('sync.accurate.view')
  const isTriggering = useIsMutating({ mutationKey: TRIGGER_SYNC_MUTATION_KEY }) > 0
  const { data: status } = useSyncStatus(canView)
  const [watching, setWatching] = useState(false)
  const [dismissed, setDismissed] = useState(false)
  const sawRunning = useRef(false)

  // Keyed on the click itself (submittedAt), not on "is the POST pending":
  // the POST now returns almost instantly (202), possibly before a render
  // ever sees it pending.
  const submits = useMutationState({
    filters: { mutationKey: TRIGGER_SYNC_MUTATION_KEY },
    select: (m) => m.state.submittedAt,
  })
  const lastSubmit = submits.length ? Math.max(...submits) : 0

  useEffect(() => {
    if (lastSubmit > 0) {
      setWatching(true)
      setDismissed(false)
      sawRunning.current = false
    }
  }, [lastSubmit])

  const running = status?.status === 'RUNNING'
  if (watching && running) sawRunning.current = true

  // Finished: the batch we were following left RUNNING -> announce once.
  useEffect(() => {
    if (watching && !isTriggering && status && status.status !== 'RUNNING' && sawRunning.current) {
      setWatching(false)
      sawRunning.current = false
      announceSyncResult(status)
    } else if (watching && !isTriggering && status && status.status !== 'RUNNING' && !sawRunning.current) {
      // finished immediately (already announced by useTriggerSync)
      setWatching(false)
    }
  }, [watching, isTriggering, status])

  const open = canView && watching && (isTriggering || running) && !dismissed
  const elapsed = useElapsedSeconds(open)
  const minutes = Math.floor(elapsed / 60)
  const seconds = elapsed % 60
  const step = status?.current_step ?? null
  const stepIndex = step ? SYNC_STEP_ORDER.indexOf(step) : -1

  return (
    <Modal
      open={open}
      onClose={() => setDismissed(true)}
      title="Sinkronisasi Accurate sedang berjalan"
      description="Proses berjalan di server — aplikasi tetap bisa dipakai. Jendela ini boleh ditutup; hasilnya akan diberitahukan."
    >
      <div className="space-y-4">
        <div className="flex items-center justify-center gap-3 rounded-md bg-muted/50 p-4">
          <Loader2 className="size-6 animate-spin text-primary" />
          <span className="font-mono text-lg tabular-nums">
            {minutes}:{seconds.toString().padStart(2, '0')}
          </span>
        </div>
        <ol className="space-y-1 text-sm">
          {SYNC_STEP_ORDER.map((s, i) => {
            const done = stepIndex > i
            const active = stepIndex === i
            return (
              <li key={s} className={`flex items-center gap-2 ${active ? 'font-medium' : done ? 'text-muted-foreground' : 'text-muted-foreground/60'}`}>
                {done ? (
                  <CheckCircle2 className="size-4 text-green-600" />
                ) : active ? (
                  <Loader2 className="size-4 animate-spin text-primary" />
                ) : (
                  <Circle className="size-4" />
                )}
                {SYNC_STEP_LABELS[s] ?? s}
              </li>
            )
          })}
        </ol>
        {(step === 'queued' || step === 'staging' || stepIndex < 0) && (
          <p className="text-xs text-muted-foreground">Menyiapkan proses…</p>
        )}
      </div>
    </Modal>
  )
}
