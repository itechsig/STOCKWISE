import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import type { UsagePeriod } from '@/features/opname/api'

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

/** Quick periods: this month, last 3 / 12 months (up to today), all time. */
export function usagePeriodPresets(): Array<{ label: string; period: UsagePeriod }> {
  const today = new Date()
  const back = (months: number) => iso(new Date(today.getFullYear(), today.getMonth() - months, today.getDate() + 1))
  return [
    { label: 'Bulan ini', period: { from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), to: iso(today) } },
    { label: '3 bulan', period: { from: back(3), to: iso(today) } },
    { label: '12 bulan', period: { from: back(12), to: iso(today) } },
    { label: 'Semua', period: {} },
  ]
}

/** "Periode NPBG/PPB" — the dates the NPBG / PPB columns count over. */
export function UsagePeriodFilter({ value, onChange, className }: { value: UsagePeriod; onChange: (p: UsagePeriod) => void; className?: string }) {
  const same = (p: UsagePeriod) => (p.from ?? '') === (value.from ?? '') && (p.to ?? '') === (value.to ?? '')
  return (
    <div className={cn('flex flex-wrap items-end gap-2 rounded-lg border bg-card p-3', className)}>
      <div className="w-full text-xs font-medium text-muted-foreground sm:w-auto sm:self-center">Periode NPBG / PPB</div>
      <label className="space-y-1 text-xs text-muted-foreground">
        Dari tanggal
        <Input type="date" className="h-9" value={value.from ?? ''} max={value.to || undefined} onChange={(e) => onChange({ ...value, from: e.target.value || undefined })} />
      </label>
      <label className="space-y-1 text-xs text-muted-foreground">
        Sampai tanggal
        <Input type="date" className="h-9" value={value.to ?? ''} min={value.from || undefined} onChange={(e) => onChange({ ...value, to: e.target.value || undefined })} />
      </label>
      <div className="inline-flex h-9 flex-wrap items-center gap-0.5 rounded-lg border bg-card p-0.5">
        {usagePeriodPresets().map((p) => (
          <button
            key={p.label}
            type="button"
            onClick={() => onChange(p.period)}
            className={cn('h-full rounded-md px-2.5 text-xs transition', same(p.period) ? 'bg-primary/10 font-medium text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground')}
          >
            {p.label}
          </button>
        ))}
      </div>
    </div>
  )
}

/** "1 Sep 2026 – 30 Sep 2026" / "s/d 30 Sep 2026" / "semua periode". */
export function usagePeriodLabel(p: UsagePeriod): string {
  const f = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
  if (p.from && p.to) return `${f(p.from)} – ${f(p.to)}`
  if (p.from) return `sejak ${f(p.from)}`
  if (p.to) return `s/d ${f(p.to)}`
  return 'semua periode'
}
