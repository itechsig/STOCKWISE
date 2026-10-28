import { Link } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, LabelList, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { AlertTriangle, ChevronRight, Clock } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { useOperationsDashboard, type OperationsDashboardData, type TrackingSummaryRow } from '@/features/dashboard/api'
import { Badge } from '@/components/ui/badge'
import { ChartCard } from './ChartCard'
// Re-exported so existing `from '@/components/dashboard/OperationsDashboard'` imports (StockCardReport.tsx) keep working.
export { ChartCard }

/** Categorical order (validated light + dark): PPB, PO, RI — same hue = same series everywhere. */
const SERIES = { ppb: '#2a78d6', po: '#eb6834', ri: '#1baf7a' }
/** State colors — done / running / needs action (never reused as a series). */
const STATE = { done: '#10b981', running: '#2563eb', attention: '#ef4444' }
/** PPB "Shortage" = received in part: the same amber as the status breakdowns. */
const SHORTAGE = '#f59e0b'

export const nf = new Intl.NumberFormat('id-ID')
const fmt = (n: number) => nf.format(n)
const tooltipNumber = (v: unknown) => (typeof v === 'number' ? fmt(v) : String(v))
/** LabelList formatter for stacked-bar segments — blank instead of a cluttering "0". */
const stackLabel = (v: unknown) => (typeof v === 'number' && v > 0 ? fmt(v) : '')

function monthLabel(ym: string): string {
  const [y, m] = ym.split('-').map(Number)
  return new Date(y, m - 1, 1).toLocaleDateString('id-ID', { month: 'short', year: '2-digit' })
}

function fmtDate(d: string | null): string {
  return d ? new Date(d.slice(0, 10) + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
}

/** Legend in series order; the swatch carries the color, the text stays in text color. */
export function SwatchLegend({ items, line }: { items: Array<[string, string]>; line?: boolean }) {
  return (
    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
      {items.map(([c, l]) => (
        <span key={l} className="inline-flex items-center gap-1.5">
          <span className={line ? 'h-0.5 w-3.5 rounded' : 'size-2.5 rounded-sm'} style={{ background: c }} />
          {l}
        </span>
      ))}
    </div>
  )
}

/** Same as SwatchLegend, but with each series' total shown right next to its name — no hover needed. */
function ValueLegend({ items }: { items: Array<{ color: string; label: string; value: number }> }) {
  return (
    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
      {items.map((it) => (
        <span key={it.label} className="inline-flex items-center gap-1.5">
          <span className="size-2.5 rounded-sm" style={{ background: it.color }} />
          <span className="text-muted-foreground">{it.label}</span>
          <span className="font-semibold tabular-nums">{fmt(it.value)}</span>
        </span>
      ))}
    </div>
  )
}

// ---------------------------------------------------------------- tracking summary

function TrackingRow({ r }: { r: TrackingSummaryRow }) {
  const parts: Array<[keyof typeof STATE, number, string]> = [
    ['done', r.done, r.words[0]],
    ['running', r.running, r.words[1]],
    ['attention', r.attention, r.words[2]],
  ]
  const pct = (n: number) => (r.total > 0 ? (n / r.total) * 100 : 0)
  return (
    <Link to={r.to} className="group block rounded-md px-2 py-2 transition hover:bg-muted/60">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 text-sm">
        <span className="font-medium">{r.label}</span>
        <span className="flex shrink-0 items-center gap-2 text-xs tabular-nums text-muted-foreground">
          {r.attention > 0 && (
            <span className="inline-flex items-center gap-1 font-medium text-red-600">
              <AlertTriangle className="size-3.5" />
              {fmt(r.attention)} {r.words[2].toLowerCase()}
            </span>
          )}
          <span>{fmt(r.running)} berjalan · {fmt(r.total)} total</span>
          <ChevronRight className="size-4 opacity-0 transition group-hover:opacity-100" />
        </span>
      </div>
      <div className="mt-1.5 flex h-2.5 w-full gap-[2px] overflow-hidden rounded bg-muted">
        {parts.filter(([, n]) => n > 0).map(([k, n, word]) => (
          <div key={k} className="h-full first:rounded-l last:rounded-r" style={{ width: `${pct(n)}%`, background: STATE[k] }} title={`${word}: ${fmt(n)} (${pct(n).toFixed(0)}%)`} />
        ))}
      </div>
    </Link>
  )
}

export function TrackingSummary({ rows }: { rows: TrackingSummaryRow[] }) {
  return (
    <ChartCard title="Status Semua Tracking" subtitle="Per modul, dengan status Excel masing-masing. Klik untuk membuka modul." empty={rows.length === 0}>
      <div className="space-y-1">
        <SwatchLegend items={[[STATE.done, 'Selesai'], [STATE.running, 'Berjalan'], [STATE.attention, 'Perlu tindakan']]} />
        <div className="-mx-2 divide-y">
          {rows.map((r) => <TrackingRow key={r.key} r={r} />)}
        </div>
      </div>
    </ChartCard>
  )
}

// ---------------------------------------------------------------- charts

export function PpbAging({ data }: { data: OperationsDashboardData['ppb_aging'] }) {
  const total = data.reduce((s, d) => s + d.requested + d.shortage, 0)
  return (
    <ChartCard title="Umur PPB Menunggu Barang" subtitle={`${fmt(total)} baris belum lengkap (Requested / Shortage, tanpa Amend) menurut umur sejak tanggal PPB`} empty={total === 0}>
      <ValueLegend items={[
        { color: SERIES.ppb, label: 'Requested', value: data.reduce((s, d) => s + d.requested, 0) },
        { color: SHORTAGE, label: 'Shortage', value: data.reduce((s, d) => s + d.shortage, 0) },
      ]} />
      <ResponsiveContainer width="100%" height={300}>
        <BarChart data={data} margin={{ top: 8, right: 8 }}>
          <CartesianGrid strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="name" tick={{ fontSize: 10 }} />
          <YAxis tick={{ fontSize: 10 }} allowDecimals={false} />
          <Tooltip formatter={tooltipNumber} cursor={{ fillOpacity: 0.3 }} />
          <Bar dataKey="requested" name="Requested" stackId="a" fill={SERIES.ppb} stroke="hsl(var(--card))" strokeWidth={1}>
            <LabelList dataKey="requested" position="center" formatter={stackLabel} className="fill-white text-[10px] font-medium" />
          </Bar>
          <Bar dataKey="shortage" name="Shortage" stackId="a" fill={SHORTAGE} stroke="hsl(var(--card))" strokeWidth={1} radius={[4, 4, 0, 0]}>
            <LabelList dataKey="shortage" position="center" formatter={stackLabel} className="fill-white text-[10px] font-medium" />
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  )
}

export function PurchaseFlow({ data }: { data: OperationsDashboardData['purchase_flow'] }) {
  const rows = data.map((d) => ({ ...d, label: monthLabel(d.month) }))
  const sum = (k: 'ppb' | 'po' | 'ri') => fmt(data.reduce((s, d) => s + d[k], 0))
  return (
    <ChartCard title="Alur Pembelian per Bulan" subtitle="Jumlah baris barang PPB → PO → RI, 12 bulan penuh terakhir. RI termasuk penerimaan tanpa PPB." empty={data.every((d) => d.ppb + d.po + d.ri === 0)}>
      <SwatchLegend line items={[[SERIES.ppb, `PPB · ${sum('ppb')} baris`], [SERIES.po, `PO · ${sum('po')} baris`], [SERIES.ri, `RI · ${sum('ri')} baris`]]} />
      <ResponsiveContainer width="100%" height={260}>
        <LineChart data={rows} margin={{ top: 8, right: 16 }}>
          <CartesianGrid strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="label" tick={{ fontSize: 10 }} />
          <YAxis tick={{ fontSize: 10 }} allowDecimals={false} tickFormatter={(v: number) => fmt(v)} />
          <Tooltip formatter={tooltipNumber} />
          <Line type="monotone" dataKey="ppb" name="PPB" stroke={SERIES.ppb} strokeWidth={2} dot={{ r: 3 }} activeDot={{ r: 5 }} />
          <Line type="monotone" dataKey="po" name="PO" stroke={SERIES.po} strokeWidth={2} dot={{ r: 3 }} activeDot={{ r: 5 }} />
          <Line type="monotone" dataKey="ri" name="RI" stroke={SERIES.ri} strokeWidth={2} dot={{ r: 3 }} activeDot={{ r: 5 }} />
        </LineChart>
      </ResponsiveContainer>
    </ChartCard>
  )
}

export function MaintenanceVehicles({ data }: { data: OperationsDashboardData['maintenance_top_vehicles'] }) {
  // axis shows the plate, the tooltip the full "W 8747 PD (HINO DUTRO)"
  const rows = data.map((d) => ({ ...d, plate: d.name.replace(/\s*\(.*$/, '') || d.name }))
  return (
    <ChartCard title="Kendaraan Paling Sering Maintenance" subtitle="Top 10 berdasarkan jumlah Sub SPK" empty={data.length === 0}>
      <ValueLegend items={[
        { color: STATE.done, label: 'Selesai', value: data.reduce((s, d) => s + d.completed, 0) },
        { color: STATE.running, label: 'Belum selesai', value: data.reduce((s, d) => s + d.open, 0) },
      ]} />
      <ResponsiveContainer width="100%" height={300}>
        <BarChart data={rows} layout="vertical" margin={{ top: 8, left: 0, right: 32 }}>
          <CartesianGrid strokeDasharray="3 3" horizontal={false} />
          <XAxis type="number" tick={{ fontSize: 10 }} allowDecimals={false} />
          <YAxis type="category" dataKey="plate" width={92} tick={{ fontSize: 10 }} interval={0} />
          <Tooltip formatter={tooltipNumber} cursor={{ fillOpacity: 0.3 }} labelFormatter={(_, p) => (p?.[0]?.payload as { name?: string } | undefined)?.name ?? ''} />
          <Bar dataKey="completed" name="Selesai" stackId="a" fill={STATE.done} stroke="hsl(var(--card))" strokeWidth={1}>
            <LabelList dataKey="completed" position="center" formatter={stackLabel} className="fill-white text-[10px] font-medium" />
          </Bar>
          <Bar dataKey="open" name="Belum selesai" stackId="a" fill={STATE.running} stroke="hsl(var(--card))" strokeWidth={1} radius={[0, 4, 4, 0]}>
            <LabelList dataKey="open" position="right" formatter={stackLabel} className="fill-foreground text-[10px]" />
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  )
}

export function TyrePerMonth({ data }: { data: OperationsDashboardData['tyre_per_month'] }) {
  const rows = data.map((d) => ({ ...d, label: monthLabel(d.month) }))
  return (
    <ChartCard title="Penggantian Ban per Bulan" subtitle="Jumlah ban diganti, 12 bulan penuh terakhir" empty={data.every((d) => d.count === 0)}>
      <ResponsiveContainer width="100%" height={318}>
        <BarChart data={rows} margin={{ top: 20, right: 8 }}>
          <CartesianGrid strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="label" tick={{ fontSize: 9 }} interval="preserveStartEnd" minTickGap={4} />
          <YAxis tick={{ fontSize: 10 }} allowDecimals={false} />
          <Tooltip formatter={tooltipNumber} cursor={{ fillOpacity: 0.3 }} />
          <Bar dataKey="count" name="Ban diganti" fill={SERIES.ppb} radius={[4, 4, 0, 0]} maxBarSize={28}>
            <LabelList dataKey="count" position="top" formatter={stackLabel} className="fill-foreground text-[10px]" />
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  )
}


// ---------------------------------------------------------------- follow-up lists

/** The oldest PPB lines still waiting for their goods (pengadaan follow-up). */
export function PpbOldestCard({ ppb }: { ppb: OperationsDashboardData['attention']['ppb_oldest'] }) {
  if (!ppb) return null
  return (
      <ChartCard title={<span className="inline-flex items-center gap-2"><Clock className="size-4 text-amber-600" />PPB Paling Lama Menunggu</span>} subtitle="Requested / Shortage tertua, belum ada barang lengkap" empty={ppb.length === 0}>
        <ul className="-mx-2 divide-y text-sm">
          {ppb.map((p) => (
            <li key={p.id}>
              <Link to={`/ppb/${p.id}`} className="flex items-start justify-between gap-3 rounded-md px-2 py-2 hover:bg-muted/60">
                <div className="min-w-0">
                  <div className="font-medium">{p.no_ppb} <span className="font-normal text-muted-foreground">· {fmtDate(p.date)}</span></div>
                  <div className="truncate text-xs text-muted-foreground" title={p.item}>{p.item}</div>
                </div>
                <div className="shrink-0 text-right">
                  <Badge variant={p.age_days > 90 ? 'danger' : 'warning'}>{fmt(p.age_days)} hari</Badge>
                  <div className="mt-0.5 text-xs tabular-nums text-muted-foreground">{fmt(p.received)}/{fmt(p.qty)} {p.unit ?? ''}</div>
                </div>
              </Link>
            </li>
          ))}
        </ul>
        <Link to="/ppb" className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">Lihat semua PPB <ChevronRight className="size-3.5" /></Link>
      </ChartCard>
  )
}

/** Lend rows past their due date (tracking follow-up). */
export function LendOverdueCard({ lend }: { lend: OperationsDashboardData['attention']['lend_overdue'] }) {
  if (!lend) return null
  return (
      <ChartCard title={<span className="inline-flex items-center gap-2"><AlertTriangle className="size-4 text-red-600" />Pinjaman Lewat Tempo</span>} subtitle="Barang dipinjamkan yang sudah melewati batas kembali" empty={lend.length === 0}>
        <ul className="-mx-2 divide-y text-sm">
          {lend.map((l) => (
            <li key={l.id}>
              <Link to={`/lend?search=${encodeURIComponent(l.number)}`} className="flex items-start justify-between gap-3 rounded-md px-2 py-2 hover:bg-muted/60">
                <div className="min-w-0">
                  <div className="font-medium">{l.number} <span className="font-normal text-muted-foreground">· {l.borrower ?? '—'}</span></div>
                  <div className="truncate text-xs text-muted-foreground" title={l.item}>{fmt(l.qty)} {l.unit ?? ''} {l.item}</div>
                </div>
                <div className="shrink-0 text-right">
                  <Badge variant="danger">{l.late_days != null ? `${fmt(l.late_days)} hari` : 'Deadline'}</Badge>
                  <div className="mt-0.5 text-xs text-muted-foreground">tempo {fmtDate(l.due_date)}</div>
                </div>
              </Link>
            </li>
          ))}
        </ul>
        <Link to="/lend" className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">Lihat semua pinjaman <ChevronRight className="size-3.5" /></Link>
      </ChartCard>
  )
}

// ---------------------------------------------------------------- section

/** Tracking modules only — purchasing (PPB / PO / RI) lives in ProcurementDashboard. */
export function TrackingDashboard() {
  const { data, isLoading, error } = useOperationsDashboard()
  if (isLoading) return <p className="text-sm text-muted-foreground">Memuat tracking…</p>
  if (error) return <p className="text-sm text-red-600">{apiErrorMessage(error)}</p>
  if (!data) return null

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <TrackingSummary rows={data.tracking} />
        <LendOverdueCard lend={data.attention.lend_overdue} />
      </div>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <MaintenanceVehicles data={data.maintenance_top_vehicles} />
        <TyrePerMonth data={data.tyre_per_month} />
      </div>
    </div>
  )
}
