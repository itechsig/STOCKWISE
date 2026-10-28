import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  LabelList,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { AlertTriangle, CheckCircle2, ChevronDown, Info, Package, PackageX, ShoppingCart, SlidersHorizontal, X } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { useInventoryDashboard, useInventoryDashboardFilters, type InventoryDashboardData, type InventoryDashboardFilters } from '@/features/dashboard/api'
import { ChartCard } from '@/components/dashboard/ChartCard'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { PriorityBadge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

// Same colors as StatusBadge (ui/badge.tsx) — status must look the same
// whether it's a badge, a chart segment, or a stacked-bar layer.
const STATUS_COLORS: Record<string, string> = { AMAN: '#10b981', TIDAK_AMAN: '#ef4444', BEP: '#94a3b8' }
/** BEP = "Stok Habis": stock AND safety stock zero — not a shortage, never "perlu dibeli". */
const STATUS_LABELS: Record<string, string> = { AMAN: 'Aman', TIDAK_AMAN: 'Tidak Aman', BEP: 'Stok Habis (BEP)' }

function fmt(n: number): string {
  return Math.round(n).toLocaleString('id-ID')
}

function fmt1(n: number): string {
  return n.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
}

/** recharts Tooltip `formatter` prop — value can be any of its ValueType variants, not just number. */
function tooltipNumber(v: unknown): string {
  return typeof v === 'number' ? fmt(v) : String(v ?? '')
}

const truncate = (v: string, n = 24) => (v.length > n ? v.slice(0, n) + '…' : v)

/** LabelList formatter for stacked-bar segments — blank instead of a cluttering "0". */
function stackLabel(v: unknown): string {
  return typeof v === 'number' && v > 0 ? fmt(v) : ''
}

/**
 * Value-annotated legend for pie/donut charts — the number sits right next to
 * each name so it's readable without hovering, with zero risk of the label
 * collisions a pie slice's own on-chart label would have for thin segments.
 */
function ValueLegend({ items, showPercent = true }: { items: { name: string; value: number; color: string }[]; showPercent?: boolean }) {
  const total = items.reduce((s, i) => s + i.value, 0)
  return (
    <ul className="flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs">
      {items.map((it) => (
        <li key={it.name} className="inline-flex items-center gap-1.5">
          <span className="size-2.5 rounded-sm" style={{ background: it.color }} />
          <span className="text-muted-foreground">{it.name}</span>
          <span className="font-semibold tabular-nums">{fmt(it.value)}</span>
          {showPercent && total > 0 && <span className="text-muted-foreground">({((it.value / total) * 100).toFixed(0)}%)</span>}
        </li>
      ))}
    </ul>
  )
}

// ---------------------------------------------------------------- KPIs

type Kpis = InventoryDashboardData['kpis']

/**
 * Item counts only. "Total Stok" / "Total Batas Aman" were dropped: they add up
 * PCS + liter + meter… into one meaningless number (and there is one warehouse).
 */
// Business rule: BEP (stok habis, tidak dipakai) counts as AMAN — only TIDAK_AMAN needs action.
const KPI_DEFS: Array<{ key: string; label: string; hint: (k: Kpis) => string; value: (k: Kpis) => number; icon: React.ReactNode; tone: 'neutral' | 'good' | 'bad' | 'muted' }> = [
  { key: 'total_barang', label: 'Total Barang', hint: () => 'Barang aktif yang dianalisa', value: (k) => k.total_barang, icon: <Package className="size-4" />, tone: 'neutral' },
  {
    key: 'aman', label: 'Aman', value: (k) => k.barang_aman + k.stok_habis,
    hint: (k) => `Termasuk ${fmt(k.stok_habis)} stok habis (BEP)`, icon: <CheckCircle2 className="size-4" />, tone: 'good',
  },
  { key: 'perlu_dibeli', label: 'Perlu Dibeli', hint: () => 'Stok di bawah safety stock', value: (k) => k.perlu_dibeli, icon: <ShoppingCart className="size-4" />, tone: 'bad' },
  { key: 'total_kekurangan', label: 'Total Kekurangan', hint: () => 'Jumlah unit kurang dari safety stock', value: (k) => k.total_kekurangan, icon: <AlertTriangle className="size-4" />, tone: 'bad' },
  {
    key: 'stok_habis', label: 'Stok Habis (BEP)', value: (k) => k.stok_habis,
    hint: () => 'Bagian dari Aman: stok & safety stock nol, tidak perlu dibeli', icon: <PackageX className="size-4" />, tone: 'muted',
  },
]

function KpiCards({ kpis }: { kpis: Kpis }) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
      {KPI_DEFS.map((k) => {
        const v = k.value(kpis)
        const bad = k.tone === 'bad' && v > 0
        return (
          <div key={k.key} className={cn('card-surface p-4', bad && 'border-red-200 bg-red-50/50 dark:bg-red-500/5')}>
            <div className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
              {k.icon}
              {k.label}
            </div>
            <div
              className={cn(
                'mt-1 text-2xl font-semibold tabular-nums',
                bad ? 'text-red-600' : k.tone === 'good' ? 'text-emerald-600' : k.tone === 'muted' ? 'text-muted-foreground' : 'text-foreground',
              )}
            >
              {fmt(v)}
            </div>
            <div className="mt-0.5 text-[11px] leading-tight text-muted-foreground">{k.hint(kpis)}</div>
          </div>
        )
      })}
    </div>
  )
}

function HealthScoreBar({ score }: { score: { value: number; category: string } }) {
  const color = score.category === 'Sehat' ? 'bg-emerald-500' : score.category === 'Perlu Perhatian' ? 'bg-amber-500' : 'bg-red-500'
  const textColor = score.category === 'Sehat' ? 'text-emerald-600' : score.category === 'Perlu Perhatian' ? 'text-amber-600' : 'text-red-600'

  return (
    <Card>
      <CardContent className="space-y-2 p-4">
        <div className="flex items-center justify-between">
          <span className="text-sm font-medium">Health Score Inventory</span>
          <span className={cn('text-sm font-semibold', textColor)}>
            {fmt1(score.value)}% · {score.category}
          </span>
        </div>
        <div className="h-3 w-full overflow-hidden rounded-full bg-muted">
          <div className={cn('h-full rounded-full transition-all', color)} style={{ width: `${Math.min(100, Math.max(0, score.value))}%` }} />
        </div>
      </CardContent>
    </Card>
  )
}


function SectionTitle({ children }: { children: React.ReactNode }) {
  return <h2 className="pt-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">{children}</h2>
}

// ---------------------------------------------------------------- page section

export function InventoryDashboard() {
  const [search, setSearch] = useState('')
  const [induk, setInduk] = useState('')
  const [status, setStatus] = useState('')
  const [showAdvanced, setShowAdvanced] = useState(false)
  const [unitId, setUnitId] = useState('')
  const [warehouseId, setWarehouseId] = useState('')
  const [needsBlueprint, setNeedsBlueprint] = useState('')
  const [hasNpbg, setHasNpbg] = useState('')
  const [leadTimeMin, setLeadTimeMin] = useState('')
  const [leadTimeMax, setLeadTimeMax] = useState('')
  const [selisihMin, setSelisihMin] = useState('')
  const [selisihMax, setSelisihMax] = useState('')
  const [highLeadTime, setHighLeadTime] = useState('')

  // filter choices from the dashboard's own endpoint (every role, no master-data rights needed)
  const { data: filterOptions } = useInventoryDashboardFilters()
  const units = filterOptions?.units
  const warehouses = filterOptions?.warehouses
  const multiWarehouse = (warehouses?.length ?? 0) > 1

  const indukOptions = filterOptions?.categories ?? []

  const filters: InventoryDashboardFilters = {
    search: search || undefined,
    accurate_category_induk: induk || undefined,
    unit_id: unitId ? Number(unitId) : undefined,
    warehouse_id: warehouseId ? Number(warehouseId) : undefined,
    status: status || undefined,
    needs_blueprint: needsBlueprint === '' ? undefined : needsBlueprint === '1',
    has_npbg: hasNpbg === '' ? undefined : hasNpbg === '1',
    lead_time_min: leadTimeMin === '' ? undefined : Number(leadTimeMin),
    lead_time_max: leadTimeMax === '' ? undefined : Number(leadTimeMax),
    selisih_min: selisihMin === '' ? undefined : Number(selisihMin),
    selisih_max: selisihMax === '' ? undefined : Number(selisihMax),
    high_lead_time_threshold: highLeadTime === '' ? undefined : Number(highLeadTime),
  }
  const advancedCount = [unitId, warehouseId, needsBlueprint, hasNpbg, leadTimeMin, leadTimeMax, selisihMin, selisihMax, highLeadTime].filter(Boolean).length
  const anyFilter = advancedCount > 0 || !!(search || induk || status)
  const resetFilters = () => {
    for (const set of [setSearch, setInduk, setStatus, setUnitId, setWarehouseId, setNeedsBlueprint, setHasNpbg, setLeadTimeMin, setLeadTimeMax, setSelisihMin, setSelisihMax, setHighLeadTime]) set('')
  }

  const { data, isLoading, isError, error } = useInventoryDashboard(filters)

  const perCategory = useMemo(
    () => [...(data?.charts.per_category ?? [])].sort((a, b) => b.TIDAK_AMAN - a.TIDAK_AMAN || b.AMAN - a.AMAN),
    [data],
  )

  return (
    <div className="space-y-4">
      {/* FILTER: main filters always visible, the rest behind "Filter lanjutan" */}
      <div className="space-y-2 print:hidden">
        <div className="grid gap-2 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-center">
          <Input placeholder="Cari kode / nama barang…" className="sm:col-span-2 lg:w-72" value={search} onChange={(e) => setSearch(e.target.value)} />
          <div className="lg:w-56">
            <Select value={induk} onChange={(e) => setInduk(e.target.value)}>
              <option value="">Semua kategori</option>
              {indukOptions.map((v) => <option key={v} value={v}>{v}</option>)}
            </Select>
          </div>
          <div className="lg:w-48">
            <Select value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">Semua status</option>
              <option value="AMAN">Aman</option>
              <option value="TIDAK_AMAN">Tidak Aman</option>
              <option value="BEP">Stok Habis (BEP)</option>
            </Select>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" className="h-10 flex-1 lg:flex-none" onClick={() => setShowAdvanced((v) => !v)}>
              <SlidersHorizontal className="size-4" /> Filter lanjutan
              {advancedCount > 0 && <span className="rounded-full bg-primary px-1.5 text-xs text-primary-foreground">{advancedCount}</span>}
              <ChevronDown className={cn('size-4 transition-transform', showAdvanced && 'rotate-180')} />
            </Button>
            {anyFilter && (
              <Button variant="ghost" className="h-10" onClick={resetFilters}>
                <X className="size-4" /> Reset
              </Button>
            )}
          </div>
        </div>

        {showAdvanced && (
          <div className="grid gap-2 rounded-lg border bg-muted/30 p-3 sm:grid-cols-2 lg:grid-cols-4">
            <Select value={unitId} onChange={(e) => setUnitId(e.target.value)}>
              <option value="">Semua satuan (UoM)</option>
              {(units ?? []).map((u) => <option key={u.id} value={u.id}>{u.code}</option>)}
            </Select>
            {multiWarehouse && (
              <Select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)}>
                <option value="">Semua gudang</option>
                {(warehouses ?? []).map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
              </Select>
            )}
            <Select value={needsBlueprint} onChange={(e) => setNeedsBlueprint(e.target.value)}>
              <option value="">Blueprint: semua</option>
              <option value="1">Perlu blueprint</option>
              <option value="0">Tidak perlu blueprint</option>
            </Select>
            <Select value={hasNpbg} onChange={(e) => setHasNpbg(e.target.value)}>
              <option value="">NPBG: semua</option>
              <option value="1">Pernah dipakai (ada NPBG)</option>
              <option value="0">Belum pernah dipakai</option>
            </Select>
            <div className="flex gap-2">
              <Input type="number" min={0} placeholder="Lead time min (hari)" value={leadTimeMin} onChange={(e) => setLeadTimeMin(e.target.value)} />
              <Input type="number" min={0} placeholder="maks" value={leadTimeMax} onChange={(e) => setLeadTimeMax(e.target.value)} />
            </div>
            <div className="flex gap-2">
              <Input type="number" placeholder="Selisih min" value={selisihMin} onChange={(e) => setSelisihMin(e.target.value)} />
              <Input type="number" placeholder="maks" value={selisihMax} onChange={(e) => setSelisihMax(e.target.value)} />
            </div>
            <Input type="number" min={0} placeholder="Ambang lead time tinggi (hari)" value={highLeadTime} onChange={(e) => setHighLeadTime(e.target.value)} />
          </div>
        )}
      </div>

      {isLoading && <p className="text-sm text-muted-foreground">Memuat data inventory…</p>}
      {isError && <p className="text-sm text-destructive">{apiErrorMessage(error, 'Gagal memuat dashboard inventory.')}</p>}

      {data && data.kpis.total_barang === 0 && (
        <Card>
          <CardContent className="flex flex-col items-center gap-2 py-10 text-center text-sm text-muted-foreground">
            <Info className="size-6" />
            {data.notes[0] ?? 'Tidak ada barang yang cocok dengan filter saat ini.'}
          </CardContent>
        </Card>
      )}

      {data && data.kpis.total_barang > 0 && (
        <>
          <KpiCards kpis={data.kpis} />
          <HealthScoreBar score={data.health_score} />

          {/* STATUS + TOP DEFICIT */}
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <ChartCard title="Status Inventory" empty={data.charts.status_distribution.every((d) => d.value === 0)}>
              <ResponsiveContainer width="100%" height={220}>
                <PieChart>
                  <Pie
                    data={data.charts.status_distribution.map((d) => ({ ...d, label: STATUS_LABELS[d.name] ?? d.name }))}
                    dataKey="value"
                    nameKey="label"
                    innerRadius={55}
                    outerRadius={90}
                    paddingAngle={2}
                  >
                    {data.charts.status_distribution.map((d) => (
                      <Cell key={d.name} fill={STATUS_COLORS[d.name] ?? '#64748b'} />
                    ))}
                  </Pie>
                  <Tooltip formatter={tooltipNumber} />
                </PieChart>
              </ResponsiveContainer>
              <ValueLegend
                items={data.charts.status_distribution.map((d) => ({ name: STATUS_LABELS[d.name] ?? d.name, value: d.value, color: STATUS_COLORS[d.name] ?? '#64748b' }))}
              />
            </ChartCard>

            <ChartCard title="Top 10 Kekurangan Stok" subtitle="Unit yang kurang dari safety stock" empty={data.charts.top_deficit.length === 0}>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={data.charts.top_deficit} layout="vertical" margin={{ left: 8, right: 32 }}>
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                  <XAxis type="number" tick={{ fontSize: 10 }} allowDecimals={false} />
                  <YAxis type="category" dataKey="description" width={150} tick={{ fontSize: 10 }} tickFormatter={(v: string) => truncate(v)} />
                  <Tooltip formatter={tooltipNumber} labelFormatter={(_, p) => p?.[0]?.payload?.description ?? ''} />
                  <Bar dataKey="deficit" name="Kurang" fill="#ef4444" radius={[0, 3, 3, 0]}>
                    <LabelList dataKey="deficit" position="right" formatter={stackLabel} className="fill-foreground text-[10px]" />
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>
          </div>

          {/* ACTION LIST + NOTES */}
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-2 text-base">
                  <ShoppingCart className="size-4" /> Yang Perlu Segera Dibeli
                </CardTitle>
              </CardHeader>
              <CardContent>
                {data.priority_items.length === 0 ? (
                  <p className="text-sm text-muted-foreground">Tidak ada barang prioritas saat ini.</p>
                ) : (
                  <div className="divide-y">
                    {data.priority_items.map((it) => (
                      <Link
                        key={it.code}
                        to={`/items?search=${encodeURIComponent(it.code)}`}
                        className="flex items-center justify-between gap-3 py-2 text-sm hover:text-primary"
                      >
                        <div className="min-w-0">
                          <div className="truncate font-medium">{it.description}</div>
                          <div className="text-xs text-muted-foreground">
                            <span className="font-mono">{it.code}</span> · kurang {fmt(it.deficit)} · lead time {it.lead_time_days} hari
                          </div>
                        </div>
                        <PriorityBadge level={it.priority_level} />
                      </Link>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-2 text-base">
                  <Info className="size-4" /> Catatan Otomatis
                </CardTitle>
              </CardHeader>
              <CardContent>
                {data.notes.length === 0 ? (
                  <p className="text-sm text-muted-foreground">Tidak ada catatan.</p>
                ) : (
                  <ul className="space-y-2 text-sm">
                    {data.notes.map((n, i) => (
                      <li key={i} className="flex gap-2">
                        <span className="text-muted-foreground">•</span>
                        <span>{n}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>

          {/* PER CATEGORY — horizontal so long category names stay readable */}
          <ChartCard title="Kondisi per Kategori" subtitle="Diurutkan dari kategori dengan barang Tidak Aman terbanyak" empty={perCategory.length === 0}>
            <ResponsiveContainer width="100%" height={Math.max(220, perCategory.length * 30 + 60)}>
              <BarChart data={perCategory} layout="vertical" margin={{ left: 8, right: 16 }}>
                <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                <XAxis type="number" tick={{ fontSize: 10 }} allowDecimals={false} />
                <YAxis type="category" dataKey="name" width={150} tick={{ fontSize: 10 }} tickFormatter={(v: string) => truncate(v)} />
                <Tooltip formatter={tooltipNumber} />
                <Bar dataKey="TIDAK_AMAN" stackId="s" fill={STATUS_COLORS.TIDAK_AMAN} name="Tidak Aman">
                  <LabelList dataKey="TIDAK_AMAN" position="center" formatter={stackLabel} className="fill-white text-[10px] font-medium" />
                </Bar>
                <Bar dataKey="AMAN" stackId="s" fill={STATUS_COLORS.AMAN} name="Aman">
                  <LabelList dataKey="AMAN" position="center" formatter={stackLabel} className="fill-white text-[10px] font-medium" />
                </Bar>
                <Bar dataKey="BEP" stackId="s" fill={STATUS_COLORS.BEP} name="Stok Habis (BEP)" radius={[0, 3, 3, 0]}>
                  <LabelList dataKey="BEP" position="center" formatter={stackLabel} className="fill-white text-[10px] font-medium" />
                </Bar>
              </BarChart>
            </ResponsiveContainer>
            <ValueLegend items={[
              { name: 'Tidak Aman', value: perCategory.reduce((s, d) => s + d.TIDAK_AMAN, 0), color: STATUS_COLORS.TIDAK_AMAN },
              { name: 'Aman', value: perCategory.reduce((s, d) => s + d.AMAN, 0), color: STATUS_COLORS.AMAN },
              { name: 'Stok Habis (BEP)', value: perCategory.reduce((s, d) => s + d.BEP, 0), color: STATUS_COLORS.BEP },
            ]} />
          </ChartCard>

          {/* per-warehouse charts only make sense with more than one warehouse */}
          {multiWarehouse && data.charts.per_warehouse.length > 1 && (
            <ChartCard title="Kondisi per Gudang" empty={false}>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={data.charts.per_warehouse}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="name" tick={{ fontSize: 10 }} />
                  <YAxis tick={{ fontSize: 10 }} allowDecimals={false} />
                  <Tooltip formatter={tooltipNumber} />
                  <Bar dataKey="TIDAK_AMAN" stackId="s" fill={STATUS_COLORS.TIDAK_AMAN} name="Tidak Aman">
                    <LabelList dataKey="TIDAK_AMAN" position="center" formatter={stackLabel} className="fill-white text-[10px] font-medium" />
                  </Bar>
                  <Bar dataKey="AMAN" stackId="s" fill={STATUS_COLORS.AMAN} name="Aman">
                    <LabelList dataKey="AMAN" position="center" formatter={stackLabel} className="fill-white text-[10px] font-medium" />
                  </Bar>
                  <Bar dataKey="BEP" stackId="s" fill={STATUS_COLORS.BEP} name="Stok Habis (BEP)" radius={[3, 3, 0, 0]}>
                    <LabelList dataKey="BEP" position="center" formatter={stackLabel} className="fill-white text-[10px] font-medium" />
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
              <ValueLegend items={[
                { name: 'Tidak Aman', value: data.charts.per_warehouse.reduce((s, d) => s + d.TIDAK_AMAN, 0), color: STATUS_COLORS.TIDAK_AMAN },
                { name: 'Aman', value: data.charts.per_warehouse.reduce((s, d) => s + d.AMAN, 0), color: STATUS_COLORS.AMAN },
                { name: 'Stok Habis (BEP)', value: data.charts.per_warehouse.reduce((s, d) => s + d.BEP, 0), color: STATUS_COLORS.BEP },
              ]} />
            </ChartCard>
          )}

          {/* USAGE (NPBG) */}
          <SectionTitle>Pemakaian Barang (NPBG)</SectionTitle>
          <ChartCard
            title="Barang Keluar per Bulan"
            subtitle={(() => {
              const last = data.charts.npbg_per_month[data.charts.npbg_per_month.length - 1]
              return last ? `Bulan terakhir (${last.name}): ${fmt(last.qty)} qty keluar · ${fmt(last.count)} NPBG` : undefined
            })()}
            empty={data.charts.npbg_per_month.length === 0}
          >
            <ResponsiveContainer width="100%" height={240}>
              <LineChart data={data.charts.npbg_per_month} margin={{ right: 16 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} />
                <XAxis dataKey="name" tick={{ fontSize: 10 }} />
                <YAxis tick={{ fontSize: 10 }} allowDecimals={false} tickFormatter={(v: number) => fmt(v)} />
                <Tooltip formatter={tooltipNumber} />
                <Line type="monotone" dataKey="qty" name="Qty keluar" stroke="#0ea5e9" strokeWidth={2} dot={{ r: 3 }} activeDot={{ r: 5 }} />
                <Line type="monotone" dataKey="count" name="Jumlah NPBG" stroke="#8b5cf6" strokeWidth={2} dot={{ r: 2 }} />
              </LineChart>
            </ResponsiveContainer>
            <ValueLegend
              showPercent={false}
              items={[
                { name: 'Total Qty keluar', value: data.charts.npbg_per_month.reduce((s, d) => s + d.qty, 0), color: '#0ea5e9' },
                { name: 'Total NPBG', value: data.charts.npbg_per_month.reduce((s, d) => s + d.count, 0), color: '#8b5cf6' },
              ]}
            />
          </ChartCard>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <ChartCard title="Barang Paling Sering Dipakai" subtitle="Top 10 berdasarkan frekuensi NPBG" empty={data.charts.npbg_top_usage.length === 0}>
              <ResponsiveContainer width="100%" height={280}>
                <BarChart data={data.charts.npbg_top_usage} layout="vertical" margin={{ left: 8, right: 32 }}>
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                  <XAxis type="number" tick={{ fontSize: 10 }} allowDecimals={false} />
                  <YAxis type="category" dataKey="name" width={150} tick={{ fontSize: 10 }} tickFormatter={(v: string) => truncate(v)} />
                  <Tooltip formatter={tooltipNumber} />
                  <Bar dataKey="count" name="Frekuensi" fill="#22c55e" radius={[0, 3, 3, 0]}>
                    <LabelList dataKey="count" position="right" formatter={stackLabel} className="fill-foreground text-[10px]" />
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>

            <ChartCard title="Divisi Pemakai Terbanyak" subtitle="Top 10 berdasarkan jumlah NPBG" empty={data.charts.npbg_top_divisi.length === 0}>
              <ResponsiveContainer width="100%" height={280}>
                <BarChart data={data.charts.npbg_top_divisi} layout="vertical" margin={{ left: 8, right: 32 }}>
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                  <XAxis type="number" tick={{ fontSize: 10 }} allowDecimals={false} />
                  <YAxis type="category" dataKey="name" width={130} tick={{ fontSize: 10 }} tickFormatter={(v: string) => truncate(v, 20)} />
                  <Tooltip formatter={tooltipNumber} />
                  <Bar dataKey="count" name="Jumlah NPBG" fill="#f59e0b" radius={[0, 3, 3, 0]}>
                    <LabelList dataKey="count" position="right" formatter={stackLabel} className="fill-foreground text-[10px]" />
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>
          </div>
        </>
      )}
    </div>
  )
}
