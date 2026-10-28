import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, LabelList, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { AlertTriangle, BookOpenCheck, ChevronRight } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { useStockCardReport, type StockCardPeriod, type StockCardReport as Report } from '@/features/dashboard/api'
import { ChartCard, SwatchLegend } from '@/components/dashboard/OperationsDashboard'
import { cn } from '@/lib/utils'

/** Masuk / Keluar: own categorical slots (violet, yellow), validated as a pair — not PPB/PO/RI's hues. */
const SERIES = { in: '#4a3aa7', out: '#eda100' }
const nf = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 })
const fmt = (n: number) => nf.format(n)

const PERIODS: Array<[StockCardPeriod, string, string]> = [
  ['daily', 'Harian', '30 hari terakhir'],
  ['weekly', 'Mingguan', '12 minggu terakhir'],
  ['monthly', 'Bulanan', '12 bulan terakhir'],
]

const PERIOD_WORD: Record<StockCardPeriod, string> = { daily: 'Hari', weekly: 'Minggu', monthly: 'Bulan' }

/**
 * Barang keluar per barang: the items that went out most in the period, each bar with
 * its own unit written at the end (units differ, so the label carries the number).
 * Click a bar to follow that item in the chart above.
 */
function OutPerItem({ items, periodHint, onPick }: { items: Report['items']; periodHint: string; onPick: (id: number) => void }) {
  const rows = items.filter((i) => i.qty_out > 0).sort((a, b) => b.qty_out - a.qty_out).slice(0, 10)
    .map((i) => ({ ...i, label: `${fmt(i.qty_out)} ${i.unit ?? ''}`.trim() }))
  return (
    <ChartCard title="Barang Keluar per Barang" subtitle={`Qty keluar ${periodHint}, 10 terbanyak · klik batang untuk melihat alurnya`} empty={rows.length === 0}>
      <ResponsiveContainer width="100%" height={Math.max(120, rows.length * 34 + 24)}>
        <BarChart data={rows} layout="vertical" margin={{ top: 4, left: 0, right: 72 }}>
          <CartesianGrid strokeDasharray="3 3" horizontal={false} />
          <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(v: number) => fmt(v)} />
          <YAxis type="category" dataKey="code" width={78} tick={{ fontSize: 10 }} interval={0} />
          <Tooltip
            cursor={{ fillOpacity: 0.08 }}
 labelFormatter={(_, p) => { const r = p?.[0]?.payload as Report['items'][number] | undefined; return r ? `${r.code} ${r.description}` : '' }}
            formatter={(_v, _n, p) => [(p?.payload as { label?: string } | undefined)?.label ?? '', 'Keluar']}
          />
          <Bar dataKey="qty_out" fill={SERIES.out} radius={[0, 4, 4, 0]} maxBarSize={22} cursor="pointer"
            onClick={(d) => onPick((d as unknown as { item_id: number }).item_id)}>
            <LabelList dataKey="label" position="right" fontSize={11} className="fill-foreground" />
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  )
}

const day = (d: string) => new Date(d + 'T00:00:00')
const short = (d: string) => day(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })
const long = (d: string) => day(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })

function bucketLabel(period: StockCardPeriod, s: Report['series'][number]): string {
  if (period === 'monthly') return day(s.start).toLocaleDateString('id-ID', { month: 'short', year: '2-digit' })
  return short(s.start)
}
function bucketTitle(period: StockCardPeriod, s: Report['series'][number], last: boolean): string {
  const base = period === 'daily' ? long(s.start)
    : period === 'weekly' ? `${short(s.start)} – ${long(s.end)}`
      : day(s.start).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' })
  return last ? `${base} (berjalan)` : base
}

/**
 * Kartu Stock report — barang masuk vs keluar per hari / minggu / bulan, for the
 * whole company or one project / item, with the per-item recap underneath.
 */
export function StockCardReport() {
  const [period, setPeriod] = useState<StockCardPeriod>('daily')
  const [projectId, setProjectId] = useState<number | null>(null)
  const [itemId, setItemId] = useState<number | null>(null)
  const { data, isLoading, error, isFetching } = useStockCardReport(period, projectId, itemId)

  if (isLoading) return <p className="text-sm text-muted-foreground">Memuat kartu stock…</p>
  if (error) return <p className="text-sm text-red-600">{apiErrorMessage(error)}</p>
  if (!data) return null

  const qty = data.measure === 'qty'
  const unit = data.unit ?? ''
  const rows = data.series.map((s, i) => ({
    label: bucketLabel(period, s),
    title: bucketTitle(period, s, i === data.series.length - 1),
    masuk: qty ? s.qty_in : s.lines_in,
    keluar: qty ? s.qty_out : s.lines_out,
  }))
  const empty = rows.every((r) => r.masuk === 0 && r.keluar === 0)
  const t = data.totals
  const periodHint = PERIODS.find(([p]) => p === period)![2]
  const tiles: Array<[string, string, string]> = [
    [qty ? 'Barang masuk' : 'Transaksi masuk', fmt(qty ? t.qty_in ?? 0 : t.lines_in), qty ? unit : 'baris'],
    [qty ? 'Barang keluar' : 'Transaksi keluar', fmt(qty ? t.qty_out ?? 0 : t.lines_out), qty ? unit : 'baris'],
    ['Barang bergerak', fmt(t.items_moved), 'jenis barang'],
  ]
  const select = 'h-9 w-full rounded-md border bg-card px-2 text-sm sm:w-auto sm:max-w-[16rem]'

  return (
    <div className={cn('space-y-4 transition-opacity', isFetching && 'opacity-70')}>
      {/* filters, one row above the chart */}
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <div className="inline-flex w-full rounded-lg border bg-card p-0.5 sm:w-auto" role="tablist" aria-label="Periode">
          {PERIODS.map(([p, label]) => (
            <button key={p} type="button" role="tab" aria-selected={period === p} onClick={() => setPeriod(p)}
              className={cn('h-8 flex-1 rounded-md px-3 text-sm font-medium sm:flex-none', period === p ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:text-foreground')}>
              {label}
            </button>
          ))}
        </div>
        <select className={select} value={projectId ?? ''} aria-label="Proyek"
          onChange={(e) => { setProjectId(e.target.value ? Number(e.target.value) : null); setItemId(null) }}>
          <option value="">Semua proyek</option>
          {data.filters.projects.map((p) => <option key={p.id} value={p.id}>{p.name}{p.status !== 'ACTIVE' ? ' (selesai)' : ''}</option>)}
        </select>
        <select className={select} value={itemId ?? ''} aria-label="Barang" onChange={(e) => setItemId(e.target.value ? Number(e.target.value) : null)}>
          <option value="">Semua barang</option>
 {data.filters.items.map((i) => <option key={i.id} value={i.id}>{i.code} {i.description}</option>)}
        </select>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {tiles.map(([label, value, hint]) => (
          <div key={label} className="card-surface p-4">
            <div className="text-xs font-medium text-muted-foreground">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums">{value} <span className="text-sm font-normal text-muted-foreground">{hint}</span></div>
            <div className="text-[11px] text-muted-foreground">{periodHint}</div>
          </div>
        ))}
        <div className={cn('card-surface p-4', t.negative_items > 0 && 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40')}>
          <div className="flex items-center gap-1 text-xs font-medium text-muted-foreground">{t.negative_items > 0 && <AlertTriangle className="size-3.5 text-red-600" />}Sisa minus</div>
          <div className={cn('mt-1 text-2xl font-semibold tabular-nums', t.negative_items > 0 && 'text-red-600')}>{fmt(t.negative_items)} <span className="text-sm font-normal text-muted-foreground">barang</span></div>
 <div className="text-[11px] text-muted-foreground">{t.negative_items > 0 ? 'keluar lebih banyak dari masuk perlu dicek' : 'aman'}</div>
        </div>
      </div>

      {/* like "Alur Pembelian per Bulan", on the date the work was done (the card line's date) */}
      <ChartCard
        title={`Alur Barang Masuk & Keluar per ${PERIOD_WORD[period]}${qty ? ` (${unit || 'qty'})` : ''}`}
        subtitle={qty
          ? `${data.filters.items.find((i) => i.id === itemId)?.description ?? ''} · menurut tanggal pengerjaan · ${periodHint}`
          : `Jumlah baris transaksi kartu stock menurut tanggal pengerjaan · ${periodHint}. Pilih satu barang untuk melihat qty.`}
        empty={empty}>
        <SwatchLegend line items={[
          [SERIES.in, `Masuk · ${fmt(qty ? t.qty_in ?? 0 : t.lines_in)} ${qty ? unit : 'baris'}`],
          [SERIES.out, `Keluar · ${fmt(qty ? t.qty_out ?? 0 : t.lines_out)} ${qty ? unit : 'baris'}`],
        ]} />
        <ResponsiveContainer width="100%" height={260}>
          <LineChart data={rows} margin={{ top: 8, right: 16, left: -8 }}>
            <CartesianGrid strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="label" tick={{ fontSize: 10 }} interval="preserveStartEnd" minTickGap={14} />
            <YAxis tick={{ fontSize: 10 }} allowDecimals={qty} width={44} tickFormatter={(v: number) => fmt(v)} />
            <Tooltip
              labelFormatter={(_, p) => (p?.[0]?.payload as { title?: string } | undefined)?.title ?? ''}
              formatter={(v, name) => [`${fmt(Number(v))} ${qty ? unit : 'baris'}`, name]}
            />
            <Line type="monotone" dataKey="masuk" name="Masuk" stroke={SERIES.in} strokeWidth={2} dot={{ r: period === 'daily' ? 2 : 3 }} activeDot={{ r: 5 }} />
            <Line type="monotone" dataKey="keluar" name="Keluar" stroke={SERIES.out} strokeWidth={2} dot={{ r: period === 'daily' ? 2 : 3 }} activeDot={{ r: 5 }} />
          </LineChart>
        </ResponsiveContainer>
      </ChartCard>

      <OutPerItem items={data.items} periodHint={periodHint} onPick={setItemId} />

      <ChartCard
        title={<span className="inline-flex items-center gap-2"><BookOpenCheck className="size-4 text-primary" />Rekap per barang</span>}
        subtitle={`Qty masuk / keluar ${periodHint}${projectId ? ` · ${data.filters.projects.find((p) => p.id === projectId)?.name ?? ''}` : ' · semua proyek'}; sisa = saldo kartu saat ini`}
        empty={data.items.length === 0}>
        {/* phone: one line per item, balance on the right */}
        <ul className="-mx-2 max-h-[28rem] divide-y overflow-y-auto sm:hidden">
          {data.items.map((r) => (
            <li key={r.item_id}>
              <button type="button" onClick={() => setItemId(r.item_id)} className="flex w-full items-center gap-3 px-2 py-2.5 text-left active:bg-muted">
                <div className="min-w-0 flex-1">
                  <div className="font-mono text-[11px] text-muted-foreground">{r.code}</div>
                  <div className="line-clamp-2 text-sm leading-snug">{r.description}</div>
                  <div className="mt-0.5 text-xs tabular-nums text-muted-foreground">
                    <span style={{ color: SERIES.in }}>●</span> Masuk {fmt(r.qty_in)} · <span style={{ color: SERIES.out }}>●</span> Keluar {fmt(r.qty_out)}
                  </div>
                </div>
                <div className="shrink-0 text-right">
                  <div className={cn('text-lg font-bold tabular-nums leading-none', r.sisa < 0 && 'text-red-600')}>{fmt(r.sisa)}</div>
                  <div className="mt-1 text-[11px] text-muted-foreground">sisa {r.unit ?? ''}</div>
                </div>
              </button>
            </li>
          ))}
        </ul>
        <div className="-mx-2 hidden max-h-[28rem] overflow-auto sm:block">
          <table className="w-full text-sm">
            <thead className="sticky top-0 z-10 bg-card">
              <tr className="border-b text-left text-xs text-muted-foreground">
                <th className="px-2 py-2 font-medium">Barang</th>
                <th className="px-2 py-2 font-medium">Satuan</th>
                <th className="px-2 py-2 text-right font-medium">Masuk</th>
                <th className="px-2 py-2 text-right font-medium">Keluar</th>
                <th className="px-2 py-2 text-right font-medium">Sisa</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {data.items.map((r) => (
                <tr key={r.item_id} className="cursor-pointer hover:bg-muted/50" onClick={() => setItemId(r.item_id)} title="Klik untuk grafik barang ini">
                  <td className="px-2 py-2">
                    <div className="font-mono text-xs text-muted-foreground">{r.code}</div>
                    <div className="line-clamp-2">{r.description}</div>
                  </td>
 <td className="px-2 py-2 text-muted-foreground">{r.unit ?? ''}</td>
 <td className="px-2 py-2 text-right tabular-nums">{r.qty_in ? fmt(r.qty_in) : ''}</td>
 <td className="px-2 py-2 text-right tabular-nums">{r.qty_out ? fmt(r.qty_out) : ''}</td>
                  <td className={cn('px-2 py-2 text-right font-semibold tabular-nums', r.sisa < 0 && 'text-red-600')}>{fmt(r.sisa)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Link to="/stock-card" className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">Buka Kartu Stock <ChevronRight className="size-3.5" /></Link>
      </ChartCard>
    </div>
  )
}
