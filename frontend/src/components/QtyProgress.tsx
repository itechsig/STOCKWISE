import { cn } from '@/lib/utils'

const fmt = (n: number) => n.toLocaleString('id-ID', { maximumFractionDigits: 2 })

/**
 * Asked / done of one line, e.g. "10/5": ordered 10, received (or handed over) 5 so far.
 * Amber while partial, green when complete, grey when nothing came yet.
 */
export function QtyProgress({ total, done, unit, className }: { total: number | null | undefined; done: number | null | undefined; unit?: string | null; className?: string }) {
  const t = Number(total ?? 0)
  const d = Number(done ?? 0)
  const full = t > 0 && d + 0.0001 >= t
  const partial = d > 0 && !full
  const pct = t > 0 ? Math.min(100, Math.round((d / t) * 100)) : 0
  return (
    <div className={cn('min-w-20', className)} title={`${fmt(d)} dari ${fmt(t)}${unit ? ` ${unit}` : ''}${partial ? ` · sisa ${fmt(Math.round((t - d) * 100) / 100)}` : ''}`}>
      <div className="flex items-baseline gap-1 whitespace-nowrap tabular-nums">
        <span className="text-muted-foreground">{fmt(t)}</span>/<span className={cn('font-semibold', full ? 'text-emerald-700' : partial ? 'text-amber-700' : 'text-muted-foreground')}>{fmt(d)}</span>
        {unit && <span className="text-xs text-muted-foreground">{unit}</span>}
      </div>
      <div className="mt-1 h-1 overflow-hidden rounded-full bg-muted">
        <div className={cn('h-full rounded-full', full ? 'bg-emerald-500' : 'bg-amber-500')} style={{ width: `${pct}%` }} />
      </div>
    </div>
  )
}
