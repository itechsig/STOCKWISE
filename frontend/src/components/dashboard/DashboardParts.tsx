import { Link } from 'react-router-dom'
import { ChevronRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

/**
 * Building blocks shared by the dashboard's sections: one heading style, KPI
 * groups per business area, and a status breakdown that replaces the old
 * index-colored donuts — status colors carry meaning, the same everywhere.
 */

const nf = new Intl.NumberFormat('id-ID')
const fmt = (n: number) => nf.format(n)

/** State colors (never reused as a series): done / in progress / partial / closed / needs action. */
export const STATE_COLOR = {
  done: '#10b981',
  running: '#2563eb',
  partial: '#f59e0b',
  closed: '#94a3b8',
  attention: '#ef4444',
} as const
export type StateTone = keyof typeof STATE_COLOR

/** Section heading: icon, title, one line saying what the section answers, and a way into the module. */
export function SectionHeading({ id, icon, title, description, link }: {
  id: string; icon: ReactNode; title: string; description: string; link?: { to: string; label: string }
}) {
  return (
    <div id={id} className="flex scroll-mt-28 flex-wrap items-end justify-between gap-x-4 gap-y-1 border-b pb-2">
      {/* `icon` stays in the signature (callers pass one) but the heading is text only */}
      <div className="min-w-0" data-icon={icon ? 'yes' : undefined}>
        <h2 className="text-base font-semibold leading-tight">{title}</h2>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      {link && (
        <Link to={link.to} className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline print:hidden">
          {link.label} <ChevronRight className="size-4" />
        </Link>
      )}
    </div>
  )
}

export interface KpiItem {
  key: string
  label: string
  value: number | string
  tone?: 'default' | 'success' | 'warning' | 'danger'
  to?: string
}

const TONE_TEXT: Record<NonNullable<KpiItem['tone']>, string> = {
  default: 'text-foreground',
  success: 'text-emerald-600',
  warning: 'text-amber-600',
  danger: 'text-red-600',
}

/** One business area's headline numbers in a single card, separated by thin dividers. */
export function KpiGroup({ title, icon, items, className }: { title: string; icon: ReactNode; items: KpiItem[]; className?: string }) {
  if (items.length === 0) return null
  return (
    <div className={cn('card-surface flex flex-col p-3', className)}>
      <div className="flex items-center gap-1.5 px-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {icon}
        {title}
      </div>
      <div className="mt-2 grid flex-1 divide-x" style={{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }}>
        {items.map((k) => {
          const body = (
            <>
              <div className={cn('text-2xl font-semibold tabular-nums leading-tight', k.value === 0 ? 'text-muted-foreground' : TONE_TEXT[k.tone ?? 'default'])}>
                {typeof k.value === 'number' ? fmt(k.value) : k.value}
              </div>
              <div className="mt-0.5 text-xs leading-snug text-muted-foreground">{k.label}</div>
            </>
          )
          return k.to ? (
            <Link key={k.key} to={k.to} className="rounded-md px-3 py-1.5 transition hover:bg-muted/60">{body}</Link>
          ) : (
            <div key={k.key} className="px-3 py-1.5">{body}</div>
          )
        })}
      </div>
    </div>
  )
}

export interface StatusRow {
  key: string
  label: string
  hint?: string
  value: number
  tone: StateTone
}

/**
 * A document's status distribution: one 100% bar (thin 2px gaps between parts)
 * and the rows below it in workflow order, each with its count and share.
 * Identity never rests on color alone — every part is labelled in the rows.
 */
export function StatusBreakdown({ rows, unit, to }: { rows: StatusRow[]; unit: string; to?: (key: string) => string }) {
  const total = rows.reduce((s, r) => s + r.value, 0)
  const pct = (n: number) => (total > 0 ? (n / total) * 100 : 0)
  return (
    <div className="space-y-3">
      <div className="flex items-baseline gap-2">
        <span className="text-2xl font-semibold tabular-nums">{fmt(total)}</span>
        <span className="text-sm text-muted-foreground">{unit}</span>
      </div>
      <div className="flex h-3 w-full gap-[2px] overflow-hidden rounded-full bg-muted" role="img"
        aria-label={rows.map((r) => `${r.label} ${fmt(r.value)}`).join(', ')}>
        {rows.filter((r) => r.value > 0).map((r) => (
          <div key={r.key} className="h-full min-w-[3px]" style={{ width: `${pct(r.value)}%`, background: STATE_COLOR[r.tone] }}
            title={`${r.label}: ${fmt(r.value)} (${pct(r.value).toFixed(1)}%)`} />
        ))}
      </div>
      <ul className="-mx-2 divide-y text-sm">
        {rows.map((r) => {
          const inner = (
            <>
              <span className="mt-1.5 size-2.5 shrink-0 rounded-sm" style={{ background: STATE_COLOR[r.tone] }} />
              <span className="min-w-0 flex-1">
                <span className="font-medium">{r.label}</span>
                {r.hint && <span className="block text-xs text-muted-foreground">{r.hint}</span>}
              </span>
              <span className="shrink-0 text-right tabular-nums">
                <span className={cn('font-semibold', r.value === 0 && 'text-muted-foreground')}>{fmt(r.value)}</span>
                <span className="ml-2 inline-block w-12 text-xs text-muted-foreground">{pct(r.value).toFixed(pct(r.value) < 10 && r.value > 0 ? 1 : 0)}%</span>
              </span>
            </>
          )
          return (
            <li key={r.key}>
              {to ? (
                <Link to={to(r.key)} className="flex items-start gap-3 rounded-md px-2 py-2 transition hover:bg-muted/60">{inner}</Link>
              ) : (
                <div className="flex items-start gap-3 px-2 py-2">{inner}</div>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
