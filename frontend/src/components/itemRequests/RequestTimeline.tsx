import { Ban, Check, X } from 'lucide-react'
import type { TimelineStep } from '@/features/itemRequests/api'
import { cn } from '@/lib/utils'

const fmtWhen = (v: string | null) =>
  v ? new Date(v).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : null

function Dot({ step, n }: { step: TimelineStep; n: number }) {
  const base = 'relative z-10 flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold'
  switch (step.state) {
    case 'done': return <span className={cn(base, 'bg-emerald-600 text-white')}><Check className="size-4" /></span>
    case 'current': return (
      <span className={cn(base, 'bg-primary text-primary-foreground ring-4 ring-primary/15')}>{n}</span>
    )
    case 'rejected': return <span className={cn(base, 'bg-red-600 text-white')}><X className="size-4" /></span>
    case 'cancelled': return <span className={cn(base, 'bg-muted-foreground text-white')}><Ban className="size-3.5" /></span>
    default: return <span className={cn(base, 'border-2 border-dashed border-muted-foreground/40 bg-card text-muted-foreground', step.state === 'skipped' && 'opacity-50')}>{n}</span>
  }
}

const STATE_TEXT: Partial<Record<TimelineStep['state'], string>> = {
  rejected: 'Ditolak',
  cancelled: 'Dibatalkan',
}

/**
 * Where a request stands: one row per step, done ones with when and by whom,
 * the current one highlighted, a rejection with its reason.
 */
export function RequestTimeline({ steps, className }: { steps: TimelineStep[]; className?: string }) {
  return (
    <ol className={cn('relative', className)}>
      {steps.map((s, i) => {
        const last = i === steps.length - 1
        const when = fmtWhen(s.at)
        return (
          <li key={s.key} className="relative flex gap-3 pb-4 last:pb-0">
            {!last && <span aria-hidden className={cn('absolute left-[13px] top-7 h-[calc(100%-1.75rem)] w-0.5', s.state === 'done' ? 'bg-emerald-600' : 'bg-border')} />}
            <Dot step={s} n={i + 1} />
            <div className={cn('min-w-0 flex-1 pt-0.5', (s.state === 'todo' || s.state === 'skipped') && 'text-muted-foreground')}>
              <div className={cn('text-sm font-medium leading-tight', s.state === 'current' && 'text-primary', s.state === 'rejected' && 'text-red-700')}>
                {s.label}
                {STATE_TEXT[s.state] && <span className="ml-1.5 text-xs font-normal">· {STATE_TEXT[s.state]}</span>}
              </div>
              {(when || s.by) && (
                <div className="mt-0.5 text-xs text-muted-foreground">{[when, s.by].filter(Boolean).join(' · ')}</div>
              )}
              {s.note && s.state === 'rejected' && <div className="mt-1 text-xs text-red-700">Alasan: {s.note}</div>}
            </div>
          </li>
        )
      })}
    </ol>
  )
}

/** A one-line progress bar of the same steps, for list cards. */
export function TimelineBar({ steps }: { steps: TimelineStep[] }) {
  const done = steps.filter((s) => s.state === 'done').length
  return (
    <div className="flex items-center gap-2" aria-label={`Langkah ${Math.min(done + 1, steps.length)} dari ${steps.length}`}>
      <div className="flex flex-1 gap-1">
        {steps.map((s) => (
          <span key={s.key} title={s.label} className={cn('h-1.5 flex-1 rounded-full',
            s.state === 'done' ? 'bg-emerald-600' : s.state === 'current' ? 'bg-primary' : s.state === 'rejected' ? 'bg-red-600' : 'bg-muted')} />
        ))}
      </div>
      <span className="shrink-0 text-xs tabular-nums text-muted-foreground">{done}/{steps.length}</span>
    </div>
  )
}

/** The same steps as a horizontal stepper (desktop page headers); falls back to the bar on phones. */
export function StepsBar({ steps }: { steps: TimelineStep[] }) {
  return (
    <>
      <div className="md:hidden"><TimelineBar steps={steps} /></div>
      <ol className="hidden items-start md:flex">
        {steps.map((s, i) => {
          const when = fmtWhen(s.at)
          return (
            <li key={s.key} className="relative flex flex-1 flex-col items-center px-1 text-center">
              {i > 0 && <span aria-hidden className={cn('absolute right-1/2 top-3.5 h-0.5 w-full -translate-y-1/2', s.state === 'done' || s.state === 'current' || s.state === 'rejected' ? 'bg-emerald-600' : 'bg-border')} />}
              <Dot step={s} n={i + 1} />
              <span className={cn('mt-1.5 text-xs font-medium leading-tight', s.state === 'current' && 'text-primary', s.state === 'rejected' && 'text-red-700',
                (s.state === 'todo' || s.state === 'skipped') && 'text-muted-foreground')}>{s.label}</span>
              {when && <span className="text-[11px] text-muted-foreground">{when}</span>}
            </li>
          )
        })}
      </ol>
    </>
  )
}
