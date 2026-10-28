import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronDown, ClipboardCheck, FileText, Info, ListChecks, PackageCheck, Plus, Rows3, Search, UserCheck, X } from 'lucide-react'
import { useOpnameLines, useOpnames, type Opname, type OpnameLineRow, type UsagePeriod } from '@/features/opname/api'
import { OpnameStatusBadge, STATUS_FILTERS, isFromAccurate, typeLabel } from '@/features/opname/status'
import { useAuth } from '@/auth/AuthContext'
import { cn } from '@/lib/utils'
import { ExportBar } from '@/components/export/ExportBar'
import { PageHeader } from '@/components/PageHeader'
import { AccurateDataNote } from '@/components/AccurateDataNote'
import { ColumnMenu, useHiddenColumns, visibleColumns } from '@/components/ColumnVisibility'
import { DataTable, type Column } from '@/components/DataTable'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { ScheduleOpnameModal } from '@/components/ScheduleOpnameModal'
import { ItemUsageCount } from '@/components/ItemUsageCount'
import { usagePeriodLabel, usagePeriodPresets } from '@/components/UsagePeriodFilter'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { useRemembered, useScrollMemory } from '@/lib/listMemory'

type Source = 'internal' | 'accurate'
type View = 'items' | 'docs'

const fmtDate = (v: string | null | undefined) =>
  v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const fmtQty = (v: number | string | null | undefined) =>
  v == null ? '—' : Number(v).toLocaleString('id-ID', { maximumFractionDigits: 2 })

function DiffValue({ line }: { line: Pick<OpnameLineRow, 'difference' | 'diff_label'> }) {
  if (line.difference == null) return <span className="text-muted-foreground">—</span>
  const d = Number(line.difference)
  if (Math.abs(d) < 0.001) return <span className="text-muted-foreground">0</span>
  return (
    <span className={cn('font-semibold tabular-nums', d > 0 ? 'text-emerald-600' : 'text-red-600')}>
      {line.diff_label ?? (d > 0 ? `+${fmtQty(d)}` : fmtQty(d))}
    </span>
  )
}

function Progress({ done, total }: { done: number; total: number }) {
  const pct = total > 0 ? Math.round((done / total) * 100) : 0
  return (
    <div className="min-w-28">
      <div className="flex justify-between text-xs">
        <span className="tabular-nums">{done}/{total}</span>
        <span className="text-muted-foreground">{pct}%</span>
      </div>
      <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
        <div className={cn('h-full rounded-full', pct === 100 ? 'bg-emerald-500' : 'bg-primary')} style={{ width: `${pct}%` }} />
      </div>
    </div>
  )
}

const FLOW = [
  { icon: Plus, title: '1. Jadwalkan', text: 'Admin memilih gudang & barang yang akan dihitung.' },
  { icon: ListChecks, title: '2. Hitung', text: 'Petugas mengisi jumlah fisik (stok sistem tidak ditampilkan).' },
  { icon: UserCheck, title: '3. Review', text: 'Admin gudang menyetujui, menolak, atau minta hitung ulang.' },
  { icon: ClipboardCheck, title: '4. Selesai', text: 'Selisih yang disetujui otomatis menyesuaikan stok.' },
]

function Segmented<T extends string>({ value, options, onChange }: { value: T; options: Array<[T, string, React.ReactNode?]>; onChange: (v: T) => void }) {
  return (
    <div className="inline-flex rounded-lg border bg-muted/40 p-1 text-sm">
      {options.map(([v, label, icon]) => (
        <button
          key={v}
          type="button"
          onClick={() => onChange(v)}
          className={cn(
            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 font-medium transition-colors',
            value === v ? 'bg-card shadow-sm' : 'text-muted-foreground hover:text-foreground',
          )}
        >
          {icon}
          {label}
        </button>
      ))}
    </div>
  )
}

/** A labelled filter control — every control in the filter panel has its label above it, so they line up. */
function Field({ label, className, children }: { label: string; className?: string; children: React.ReactNode }) {
  return (
    <div className={cn('space-y-1', className)}>
      <div className="text-xs font-medium text-muted-foreground">{label}</div>
      {children}
    </div>
  )
}

/** "from – to" date pair under one label (instead of two separately labelled boxes). */
function DateRange({ label, from, to, onFrom, onTo }: { label: string; from: string; to: string; onFrom: (v: string) => void; onTo: (v: string) => void }) {
  return (
    <Field label={label} className="w-full sm:w-auto">
      <div className="flex items-center gap-1.5">
        <Input type="date" aria-label={`${label}: dari`} className="h-9 min-w-0 flex-1 sm:w-40 sm:flex-none" value={from} max={to || undefined} onChange={(e) => onFrom(e.target.value)} />
        <span className="text-sm text-muted-foreground">–</span>
        <Input type="date" aria-label={`${label}: sampai`} className="h-9 min-w-0 flex-1 sm:w-40 sm:flex-none" value={to} min={from || undefined} onChange={(e) => onTo(e.target.value)} />
      </div>
    </Field>
  )
}

/** Accurate source: one line of context + the sync note; the column glossary opens on demand. */
function AccurateInfo({ canReview }: { canReview: boolean }) {
  const [open, setOpen] = useState(false)
  return (
    <div className="rounded-lg border bg-muted/30 px-3 py-2.5 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5">
        <div className="flex min-w-0 items-start gap-2">
          <Info className="mt-0.5 size-4 shrink-0 text-primary" />
          <span>
            Penyesuaian persediaan yang dibuat di Accurate.{' '}
            <button type="button" onClick={() => setOpen((v) => !v)} aria-expanded={open}
              className="inline-flex items-center gap-0.5 whitespace-nowrap font-medium text-primary hover:underline">
              Arti kolom <ChevronDown className={cn('size-4 transition-transform', open && 'rotate-180')} />
            </button>
          </span>
        </div>
        <AccurateDataNote />
      </div>
      {open && (
        <dl className="mt-2.5 grid gap-x-6 gap-y-1.5 border-t pt-2.5 text-xs sm:grid-cols-2">
          {canReview && (
            <>
              <div><dt className="inline font-semibold">Qty sebelum</dt> <dd className="inline text-muted-foreground">stok di Accurate sebelum penyesuaian</dd></div>
              <div><dt className="inline font-semibold">Qty sesudah</dt> <dd className="inline text-muted-foreground">hasil hitung fisik</dd></div>
            </>
          )}
          <div><dt className="inline font-semibold">Saldo awal</dt> <dd className="inline text-muted-foreground">saldo persediaan awal (31/12/2024)</dd></div>
          <div><dt className="inline font-semibold">Penyesuaian stok</dt> <dd className="inline text-muted-foreground">penyesuaian sehari-hari</dd></div>
        </dl>
      )}
    </div>
  )
}

export function OpnameListPage() {
  const { hasPermission } = useAuth()
  const navigate = useNavigate()
  const canReview = hasPermission('opname.review')
  const [source, setSource] = useRemembered<Source>('stock-opnames', 'source', 'accurate')
  const [view, setView] = useRemembered<View>('stock-opnames', 'view', 'items')
  const [status, setStatus] = useRemembered('stock-opnames', 'status', '')
  const [search, setSearch] = useRemembered('stock-opnames', 'search', '')
  const [dateFrom, setDateFrom] = useRemembered('stock-opnames', 'dateFrom', '')
  // "Per barang": period of the NPBG / PPB columns (apart from the opname date above)
  const [period, setPeriod] = useRemembered<UsagePeriod>('stock-opnames', 'period', {})
  const [dateTo, setDateTo] = useRemembered('stock-opnames', 'dateTo', '')
  const [onlyDiff, setOnlyDiff] = useRemembered('stock-opnames', 'onlyDiff', false)
  const [page, setPage] = useRemembered('stock-opnames', 'page', 1)
  const [scheduleOpen, setScheduleOpen] = useState(false)

  const filters = {
    source,
    status: status || undefined,
    search: search.trim() || undefined,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
    page,
  }
  const docs = useOpnames(filters, view === 'docs')
  const lines = useOpnameLines({ ...filters, only_diff: onlyDiff, usage_from: period.from, usage_to: period.to }, view === 'items')
  // scroll to the end instead of Sebelumnya/Berikutnya
  const lineScroll = useInfiniteRows(lines.data, page, setPage, 'stock-opnames:lines')
  const docScroll = useInfiniteRows(docs.data, page, setPage, 'stock-opnames:docs')
  const mobileScroll = useScrollMemory('stock-opnames:lines-phone', lineScroll.rows.length > 0)

  const acc = source === 'accurate'
  const hasFilters = !!(search || dateFrom || dateTo || status || onlyDiff)

  const resetPage = <T,>(set: (v: T) => void) => (v: T) => {
    set(v)
    setPage(1)
  }
  const clearFilters = () => {
    setSearch('')
    setDateFrom('')
    setDateTo('')
    setStatus('')
    setOnlyDiff(false)
    setPage(1)
  }
  const open = (id: number) => navigate(`/stock-opnames/${id}`)

  // ---- "Per barang": one row per item, no need to open each document
  const lineColumns: Column<OpnameLineRow>[] = [
    {
      key: 'date',
      header: 'Tanggal',
      className: 'whitespace-nowrap',
      cell: (r) => fmtDate(r.opname.date),
    },
    {
      key: 'doc',
      header: 'No. Dokumen',
      cell: (r) => <span className="font-mono text-xs font-medium text-primary">{r.opname.number}</span>,
    },
    {
      key: 'item',
      header: 'Barang',
      cell: (r) => (
        <div className="min-w-56">
          <div className="font-mono text-xs text-muted-foreground">{r.item_code}</div>
          <div className="leading-snug">{r.description}</div>
        </div>
      ),
    },
    { key: 'unit', header: 'Satuan', cell: (r) => r.unit ?? '—' },
    { key: 'npbg', header: 'NPBG', className: 'text-right', cell: (r) => <ItemUsageCount kind="npbg" code={r.item_code} count={r.npbg_count} period={period} /> },
    { key: 'ppb', header: 'PPB', className: 'text-right', cell: (r) => <ItemUsageCount kind="ppb" code={r.item_code} count={r.ppb_count} period={period} /> },
    ...(canReview
      ? [{ key: 'before', header: acc ? 'Qty sebelum' : 'Stok sistem', className: 'text-right', cell: (r: OpnameLineRow) => <span className="tabular-nums">{fmtQty(r.system_qty)}</span> }]
      : []),
    { key: 'after', header: acc ? 'Qty sesudah' : 'Jumlah fisik', className: 'text-right', cell: (r) => <span className="tabular-nums font-medium">{fmtQty(r.physical_qty)}</span> },
    ...(canReview ? [{ key: 'diff', header: 'Selisih', className: 'text-right', cell: (r: OpnameLineRow) => <DiffValue line={r} /> }] : []),
    ...(!acc ? [{ key: 'note', header: 'Catatan', cell: (r: OpnameLineRow) => <span className={cn(!r.note && 'text-muted-foreground')}>{r.note ?? '—'}</span> }] : []),
    { key: 'status', header: 'Status', cell: (r) => <OpnameStatusBadge opname={r.opname} /> },
  ]

  // ---- "Per dokumen"
  const docColumns: Column<Opname>[] = [
    { key: 'date', header: 'Tanggal', className: 'whitespace-nowrap', cell: (r) => fmtDate(r.scheduled_date) },
    { key: 'number', header: 'No. Dokumen', cell: (r) => <span className="font-mono text-xs font-medium text-primary">{r.number}</span> },
    {
      key: 'items',
      header: 'Barang',
      cell: (r) => (
        <div className="min-w-56">
          {r.first_item ? (
            <>
              <div className="leading-snug">{r.first_item.description}</div>
              {(r.items_count ?? 0) > 1 && <div className="text-xs text-muted-foreground">+{(r.items_count ?? 0) - 1} barang lainnya</div>}
            </>
          ) : (
            <span className="text-muted-foreground">—</span>
          )}
        </div>
      ),
    },
    { key: 'wh', header: 'Gudang', cell: (r) => r.warehouse?.code ?? '—' },
    ...(!acc ? [{ key: 'type', header: 'Cakupan', cell: (r: Opname) => typeLabel(r.type) }] : []),
    {
      key: 'prog',
      header: acc ? 'Jumlah barang' : 'Progres hitung',
      cell: (r) => (isFromAccurate(r) ? <span className="tabular-nums">{r.items_count ?? 0}</span> : <Progress done={r.counted_count ?? 0} total={r.items_count ?? 0} />),
    },
    ...(canReview && !acc
      ? [{
          key: 'diff',
          header: 'Selisih',
          cell: (r: Opname) => ((r.diff_count ?? 0) > 0 ? <Badge variant="warning">{r.diff_count} barang</Badge> : <span className="text-muted-foreground">—</span>),
        }]
      : []),
    { key: 'status', header: 'Status', cell: (r) => <OpnameStatusBadge opname={r} /> },
  ]

  const emptyText = hasFilters
    ? 'Tidak ada data untuk filter ini.'
    : acc
      ? 'Belum ada data opname dari Accurate.'
      : 'Belum ada stock opname. Klik "Jadwalkan Opname" untuk membuat jadwal hitung.'

  // Hide-kolom terpisah untuk tiap tampilan ("Per barang" vs "Per dokumen") —
  // juga menentukan kolom apa yang ikut ke export/cetak (lihat ExportBar di bawah).
  const [hiddenLineCols, toggleLineCol] = useHiddenColumns('stockwise:opname-line-hidden-columns')
  const [hiddenDocCols, toggleDocCol] = useHiddenColumns('stockwise:opname-doc-hidden-columns')
  const shownLineColumns = visibleColumns(lineColumns, hiddenLineCols, ['doc'])
  const shownDocColumns = visibleColumns(docColumns, hiddenDocCols, ['number'])
  const exportDataset = view === 'items' ? 'stock-opname-lines' : 'stock-opnames'
  const exportColumns = (view === 'items' ? shownLineColumns : shownDocColumns).map((c) => c.key).join(',')
  const exportFilters = view === 'items' ? { ...filters, only_diff: onlyDiff, usage_from: period.from, usage_to: period.to } : filters

  return (
    <div className="space-y-4">
      <PageHeader
        title="Stock Opname"
        subtitle="Hitung fisik barang di gudang dan penyesuaian stok"
        icon={<PackageCheck className="size-5" />}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ExportBar dataset={exportDataset} filters={exportFilters} filenameBase="stock-opname" viewPermission="report.opname" columns={exportColumns} />
            {hasPermission('opname.schedule') && (
              <Button size="sm" onClick={() => setScheduleOpen(true)}>
                <Plus className="size-4" /> Jadwalkan Opname
              </Button>
            )}
          </div>
        }
      />

      {/* what to look at: source + layout */}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Segmented<Source>
          value={source}
          options={[['accurate', 'Dari Accurate'], ['internal', 'Opname STOCKWISE']]}
          onChange={(v) => {
            setSource(v)
            setView(v === 'accurate' ? 'items' : 'docs')
            setStatus('')
            setPage(1)
          }}
        />
        <Segmented<View>
          value={view}
          options={[['items', 'Per barang', <Rows3 key="i" className="size-4" />], ['docs', 'Per dokumen', <FileText key="d" className="size-4" />]]}
          onChange={resetPage(setView)}
        />
      </div>

      {acc ? <AccurateInfo canReview={canReview} /> : (
        <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
          {FLOW.map(({ icon: Icon, title, text }) => (
            <div key={title} className="flex items-start gap-2 rounded-lg border bg-card p-3 text-sm">
              <Icon className="mt-0.5 size-4 shrink-0 text-primary" />
              <div>
                <div className="font-medium">{title}</div>
                <div className="text-xs text-muted-foreground">{text}</div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* filters: one panel, every control labelled, the two date ranges told apart */}
      <div className="card-surface space-y-3 p-3">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Cari" className="min-w-0 basis-full sm:basis-72 sm:flex-1">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                className="h-9 pl-9"
                placeholder={view === 'items' ? 'Kode / nama barang / no. dokumen…' : 'No. dokumen…'}
                value={search}
                onChange={(e) => resetPage(setSearch)(e.target.value)}
              />
            </div>
          </Field>
          <DateRange label="Tanggal opname" from={dateFrom} to={dateTo} onFrom={resetPage(setDateFrom)} onTo={resetPage(setDateTo)} />
          {!acc && (
            <Field label="Status" className="w-full sm:w-52">
              <Select className="h-9" value={status} onChange={(e) => resetPage(setStatus)(e.target.value)}>
                <option value="">Semua status</option>
                {STATUS_FILTERS.map((st) => <option key={st.value} value={st.value}>{st.label}</option>)}
              </Select>
            </Field>
          )}
          <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
            {view === 'items' && canReview && !acc && (
              <label className="flex h-9 items-center gap-2 text-sm">
                <input type="checkbox" className="size-4" checked={onlyDiff} onChange={(e) => resetPage(setOnlyDiff)(e.target.checked)} />
                Hanya yang selisih
              </label>
            )}
            {hasFilters && (
              <Button variant="ghost" size="sm" className="h-9" onClick={clearFilters}>
                <X className="size-4" /> Reset
              </Button>
            )}
            {view === 'items'
              ? <ColumnMenu columns={lineColumns} hiddenCols={hiddenLineCols} onToggle={toggleLineCol} alwaysVisible={['doc']} />
              : <ColumnMenu columns={docColumns} hiddenCols={hiddenDocCols} onToggle={toggleDocCol} alwaysVisible={['number']} />}
          </div>
        </div>

        {view === 'items' && (
          <div className="flex flex-wrap items-end gap-3 border-t pt-3">
            <DateRange label="Periode kolom NPBG / PPB" from={period.from ?? ''} to={period.to ?? ''}
              onFrom={(v) => setPeriod({ ...period, from: v || undefined })} onTo={(v) => setPeriod({ ...period, to: v || undefined })} />
            <div className="inline-flex flex-wrap rounded-lg border bg-muted/40 p-1 text-sm" role="group" aria-label="Periode cepat NPBG / PPB">
              {usagePeriodPresets().map((p) => {
                const on = (p.period.from ?? '') === (period.from ?? '') && (p.period.to ?? '') === (period.to ?? '')
                return (
                  <button key={p.label} type="button" onClick={() => setPeriod(p.period)} aria-pressed={on}
                    className={cn('rounded-md px-3 py-1 font-medium transition-colors', on ? 'bg-card shadow-sm' : 'text-muted-foreground hover:text-foreground')}>
                    {p.label}
                  </button>
                )
              })}
            </div>
            <p className="basis-full text-xs text-muted-foreground xl:ml-auto xl:basis-auto xl:self-center">
              NPBG / PPB = jumlah baris tiap barang, {usagePeriodLabel(period)}. Klik angkanya untuk melihat daftarnya.
            </p>
          </div>
        )}
      </div>

      {view === 'items' ? (
        <>
          {/* tablet / desktop */}
          <div className="hidden md:block">
            <DataTable
              columns={shownLineColumns}
              rows={lineScroll.rows} scrollMemoryKey="stock-opnames:lines"
              notes={{ entity: 'opname_line' }} rowKey={(r) => r.id}
              isLoading={lines.isLoading}
              onRowClick={(r) => open(r.opname.id)}
              emptyText={emptyText}
              containerClassName="max-h-[70vh] overflow-y-auto"
              stickyHeader
              footer={lineScroll.footer}
            />
          </div>
          {/* phone */}
          <div ref={mobileScroll} className="max-h-[75vh] space-y-2 overflow-y-auto md:hidden">
            {lines.isLoading && <p className="py-6 text-center text-sm text-muted-foreground">Memuat…</p>}
            {!lines.isLoading && lineScroll.rows.length === 0 && <p className="py-6 text-center text-sm text-muted-foreground">{emptyText}</p>}
            {lineScroll.rows.map((r) => (
              <button key={r.id} type="button" onClick={() => open(r.opname.id)} className="w-full space-y-2 rounded-lg border bg-card p-3 text-left text-sm active:bg-muted">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="font-mono text-xs text-muted-foreground">{r.item_code}</div>
                    <div className="font-medium leading-snug">{r.description}</div>
                  </div>
                  <OpnameStatusBadge opname={r.opname} />
                </div>
                <div className="flex flex-wrap gap-x-4 gap-y-1">
                  {canReview && <span><span className="text-muted-foreground">{acc ? 'Sebelum' : 'Sistem'}:</span> <b className="tabular-nums">{fmtQty(r.system_qty)}</b></span>}
                  <span><span className="text-muted-foreground">{acc ? 'Sesudah' : 'Fisik'}:</span> <b className="tabular-nums">{fmtQty(r.physical_qty)}</b> {r.unit}</span>
                  {canReview && <span><span className="text-muted-foreground">Selisih:</span> <DiffValue line={r} /></span>}
                  <span><span className="text-muted-foreground">NPBG:</span> <b className="tabular-nums">{r.npbg_count ?? 0}</b></span>
                  <span><span className="text-muted-foreground">PPB:</span> <b className="tabular-nums">{r.ppb_count ?? 0}</b></span>
                </div>
                <div className="text-xs text-muted-foreground">
                  {fmtDate(r.opname.date)} · <span className="font-mono">{r.opname.number}</span>
                  {r.note && <> · {r.note}</>}
                </div>
              </button>
            ))}
            {lineScroll.footer}
          </div>
        </>
      ) : (
        <DataTable
          columns={shownDocColumns}
          rows={docScroll.rows} scrollMemoryKey="stock-opnames:docs"
          notes={{ entity: 'opname' }} rowKey={(r) => r.id}
          isLoading={docs.isLoading}
          onRowClick={(r) => open(r.id)}
          emptyText={emptyText}
          containerClassName="max-h-[70vh] overflow-y-auto"
          stickyHeader
          footer={docScroll.footer}
        />
      )}


      <ScheduleOpnameModal
        open={scheduleOpen}
        onClose={() => setScheduleOpen(false)}
        onScheduled={(o) => {
          setScheduleOpen(false)
          if (o) open(o.id)
        }}
      />
    </div>
  )
}
