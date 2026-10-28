import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Boxes, Clock, Columns3, Download, FileText, Loader2, PackagePlus, Pencil, Printer, ShieldCheck, Trash2, X } from 'lucide-react'
import { useRestock } from '@/features/ppbDrafts/api'
import { useAccurateCategoryOptions, useInventoryAnalysis, type ItemLookupResult } from '@/features/inventory/api'
import {
  useCreateSafetyStock,
  useDeleteSafetyStock,
  useResolveSafetyStockConflict,
  useSafetyStockList,
  useUpdateSafetyStock,
  type SafetyStockPayload,
  type SafetyStockRow,
} from '@/features/safety-stock/api'
import { useAuth } from '@/auth/AuthContext'
import { api, apiErrorMessage } from '@/lib/api'
import { ItemPicker } from '@/components/ItemPicker'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, type Column } from '@/components/DataTable'
import { Badge, PriorityBadge, StatusBadge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import type { AnalysisRow } from '@/types/inventory'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { confirmDialog } from '@/components/ui/confirm'
import { FilterBar } from '@/components/FilterBar'
import { DropdownMenu } from '@/components/ui/dropdown'

// ============================================================ Tab 1 — Analisis Inventory

// Key harus sama persis dengan ExportController::inventoryAnalysisColumns() (backend) —
// itulah yang membuat "sembunyikan kolom" di sini juga berarti "jangan ikut di export/cetak".
const analysisColumns: Column<AnalysisRow>[] = [
  { key: 'code', header: 'Kode', cell: (r) => <span className="font-mono text-xs">{r.item.code}</span> },
  { key: 'description', header: 'Deskripsi', cell: (r) => r.item.description },
  { key: 'available', header: 'Tersedia', cell: (r) => (r.stock_known ? r.available : 'UNKNOWN') },
  { key: 'safety_stock', header: 'Safety Stock', cell: (r) => r.safety_stock },
 { key: 'min_pr', header: 'MIN PR', cell: (r) => r.min_pr ?? '' },
 { key: 'avg_usage_1m', header: '1 Bulan', cell: (r) => (r.avg_usage_1m != null ? Math.round(r.avg_usage_1m) : '') },
 { key: 'avg_usage_3m', header: '3 Bulan', cell: (r) => (r.avg_usage_3m != null ? Math.round(r.avg_usage_3m) : '') },
 { key: 'avg_usage_6m', header: '6 Bulan', cell: (r) => (r.avg_usage_6m != null ? Math.round(r.avg_usage_6m) : '') },
 { key: 'avg_usage_12m', header: '12 Bulan', cell: (r) => (r.avg_usage_12m != null ? Math.round(r.avg_usage_12m) : '') },
  { key: 'selisih', header: 'Selisih', cell: (r) => r.selisih },
  { key: 'deficit', header: 'Defisit', cell: (r) => r.deficit },
 { key: 'lead_time', header: 'LT', cell: (r) => r.lead_time_days ?? '' },
  { key: 'priority_score', header: 'Skor', cell: (r) => r.priority_score },
  { key: 'status', header: 'Status', cell: (r) => <StatusBadge status={r.status} /> },
  { key: 'priority_level', header: 'Prioritas', cell: (r) => <PriorityBadge level={r.priority_level} /> },
  {
    key: 'on_order',
    header: 'Dalam pesanan',
    cell: (r) => (r.on_order?.total ? <span className="whitespace-nowrap text-xs" title={r.on_order.docs.join(', ')}><b className="tabular-nums">{r.on_order.total.toLocaleString('id-ID')}</b> <span className="text-muted-foreground">{r.on_order.docs[0]}{r.on_order.docs.length > 1 ? ` +${r.on_order.docs.length - 1}` : ''}</span></span> : ''),
  },
  {
    key: 'recommendation',
    header: 'Rekomendasi',
    cell: (r) => <span className="text-xs text-muted-foreground">{r.recommendation}</span>,
    className: 'max-w-xs',
  },
]

const ANALYSIS_HIDDEN_COLUMNS_KEY = 'stockwise:inventory-analysis-hidden-columns'

function loadHiddenAnalysisColumns(): Set<string> {
  try {
    const raw = localStorage.getItem(ANALYSIS_HIDDEN_COLUMNS_KEY)
    return raw ? new Set(JSON.parse(raw) as string[]) : new Set()
  } catch {
    return new Set()
  }
}

/** Analisis Inventory — status stok tiap barang terhadap Safety Stock-nya, dengan rekomendasi pembelian. Selalu real-time (lihat InventoryAnalyzer::compute() di backend) — tidak ada tombol "Hitung ulang". */
/** Already on its way (Draft PPB / PPB / PO not received yet): Restock is locked until the RI. */
const onOrder = (r: AnalysisRow) => (r.on_order?.total ?? 0) > 0

function AnalysisTab() {
  const { hasPermission } = useAuth()
  // Restock: pick items (or one row's button) → a Draft PPB to review / edit before it becomes a PPB
  const canRestock = hasPermission('ppb.create')
  const restock = useRestock()
  const [picked, setPicked] = useState<Map<number, AnalysisRow>>(new Map())
  const togglePick = (r: AnalysisRow) => setPicked((m) => { const n = new Map(m); if (n.has(r.item.id)) n.delete(r.item.id); else n.set(r.item.id, r); return n })
  // stays on this page: the rows turn "Dipesan" (locked until the goods are received by RI)
  const startRestock = (rows: AnalysisRow[]) => restock.mutate(rows.filter((r) => !onOrder(r)).map((r) => ({ item_id: r.item.id })), {
    onSuccess: () => setPicked(new Map()),
    onError: (e) => setErr(apiErrorMessage(e)),
  })
  const [search, setSearch] = useState('')
  // Default "Semua status" — kartu "Total item" di atas selalu menghitung
  // seluruh barang aktif (lihat InventoryAnalyzer::compute()); defaultnya pernah
  // "TIDAK_AMAN" saja, yang membuat tabel cuma menampilkan sebagian kecil barang
  // walau semuanya sebenarnya sudah dianalisis — kelihatan seperti data master
  // tidak lengkap dianalisis.
  const [status, setStatus] = useState('')
  const [priorityLevel, setPriorityLevel] = useState('')
  const [category, setCategory] = useState('')
  const [page, setPage] = useState(1)
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState<'xlsx' | 'csv' | 'pdf' | 'print' | null>(null)

  const [hiddenCols, setHiddenCols] = useState<Set<string>>(loadHiddenAnalysisColumns)
  const [colMenuOpen, setColMenuOpen] = useState(false)
  useEffect(() => {
    try {
      localStorage.setItem(ANALYSIS_HIDDEN_COLUMNS_KEY, JSON.stringify([...hiddenCols]))
    } catch {
      // localStorage tidak tersedia (mode privat, dll) — abaikan, cukup tidak persisten.
    }
  }, [hiddenCols])
  function toggleColumn(key: string) {
    setHiddenCols((prev) => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }
  // Kode selalu tampil — sisanya bisa disembunyikan (di layar maupun di hasil export/cetak).
  const toggleableColumns = analysisColumns.filter((c) => c.key !== 'code')
  const shownColumns = analysisColumns.filter((c) => c.key === 'code' || !hiddenCols.has(c.key))
  const visibleColumnKeys = shownColumns.map((c) => c.key).join(',')
  // pick + Restock columns only on screen (never part of the export / print columns)
  const visibleColumns: Column<AnalysisRow>[] = !canRestock ? shownColumns : [
    {
      key: '__pick',
      header: <span className="sr-only">Pilih</span>,
      className: 'w-8',
      cell: (r) => <input type="checkbox" className="size-4 accent-primary disabled:opacity-40" aria-label={`Pilih ${r.item.code} untuk restock`}
        disabled={onOrder(r)} checked={picked.has(r.item.id)} onChange={() => togglePick(r)} onClick={(e) => e.stopPropagation()} />,
    },
    ...shownColumns,
    {
      key: '__restock',
      header: <span className="sr-only">Restock</span>,
      className: 'text-right',
      cell: (r) => onOrder(r) ? (
        <Button size="sm" variant="ghost" className="h-7 cursor-not-allowed px-2 text-muted-foreground" disabled
          title={`Sedang dipesan ${r.on_order!.total.toLocaleString('id-ID')} (${r.on_order!.docs.join(', ')}) — bisa restock lagi setelah barang diterima (RI)`}>
          <Clock className="size-3.5" /> Dipesan
        </Button>
      ) : (
        <Button size="sm" variant={r.status === 'AMAN' ? 'ghost' : 'outline'} className="h-7 px-2" disabled={restock.isPending}
          onClick={(e) => { e.stopPropagation(); startRestock([r]) }} title={`Masukkan ${r.item.code} ke Draft PPB (saran ${r.recommended_qty})`}>
          {restock.isPending && restock.variables?.some((v) => v.item_id === r.item.id) ? <Loader2 className="size-3.5 animate-spin" /> : <PackagePlus className="size-3.5" />} Restock
        </Button>
      ),
    },
  ]

  const { data: branches } = useAccurateCategoryOptions()
  // sama seperti dropdown Kategori Induk di Master Barang — daftar nilai nyata, bukan enum tetap
  const indukOptions = useMemo(
    () => [...new Set(branches?.map((b) => b.accurate_category_induk).filter((v): v is string => v != null) ?? [])].sort(),
    [branches]
  )

  // dipakai juga untuk export/cetak — hasil ikut filter yang sedang aktif; kosong = semua data
  const activeFilters = {
    search: search || undefined,
    status: status || undefined,
    priority_level: priorityLevel || undefined,
    category_induk: category || undefined,
  }

  const { data, isLoading, isError } = useInventoryAnalysis({
    ...activeFilters,
    page,
    per_page: 25,
    sort: '-priority_score',
  })
  // scroll to the end instead of Sebelumnya/Berikutnya (pages appended while scrolling)
  const { rows: scrollRows, footer: scrollFooter } = useInfiniteRows(data, page, setPage)

  const canExportExcel = hasPermission('inventory.view_analysis') && hasPermission('export.excel')
  const canExportCsv = hasPermission('inventory.view_analysis') && hasPermission('export.csv')
  const canExportPdf = hasPermission('inventory.view_analysis') && hasPermission('export.pdf')

  async function exportFile(format: 'xlsx' | 'csv' | 'pdf') {
    setBusy(format)
    setErr(null)
    try {
      const res = await api.get('/api/export/inventory_analysis', {
        params: { ...activeFilters, format, columns: visibleColumnKeys },
        responseType: 'blob',
      })
      const disposition = String(res.headers['content-disposition'] ?? '')
      const match = disposition.match(/filename="?([^"]+)"?/)
      const name = match?.[1] ?? `analisis-inventory.${format === 'pdf' ? 'html' : format}`
      const url = URL.createObjectURL(res.data as Blob)
      const a = document.createElement('a')
      a.href = url
      a.download = name
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(null)
    }
  }

  async function printNow() {
    setBusy('print')
    setErr(null)
    const w = window.open('', '_blank')
    try {
      const res = await api.get('/api/export/inventory_analysis', {
        params: { ...activeFilters, format: 'pdf', columns: visibleColumnKeys },
        responseType: 'text',
      })
      if (w) {
        w.document.write(res.data as string)
        w.document.close()
      }
    } catch (e) {
      w?.close()
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-end gap-2">
        <DropdownMenu label={busy ? 'Menyiapkan…' : 'Export'} icon={Download} busy={busy !== null} items={[
          { label: 'Excel (.xlsx)', icon: Download, onSelect: () => exportFile('xlsx'), hidden: !canExportExcel },
          { label: 'CSV', icon: Download, onSelect: () => exportFile('csv'), hidden: !canExportCsv },
          { label: 'PDF', icon: FileText, onSelect: () => exportFile('pdf'), hidden: !canExportPdf },
          { label: 'Cetak', icon: Printer, onSelect: () => printNow(), hidden: !canExportPdf },
        ]} />
        <div className="relative">
          <Button size="sm" variant="outline" onClick={() => setColMenuOpen((v) => !v)}>
            <Columns3 className="size-4" /> Kolom
          </Button>
          {colMenuOpen && (
            <>
              <div className="fixed inset-0 z-10" onClick={() => setColMenuOpen(false)} />
              <div className="absolute right-0 z-20 mt-1 max-h-80 w-64 overflow-y-auto rounded-lg border bg-card p-1.5 shadow-lg">
                <p className="px-2 py-1 text-xs text-muted-foreground">Kolom tersembunyi tidak ikut di export/cetak.</p>
                {toggleableColumns.map((c) => (
                  <label key={c.key} className="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted/60">
                    <input type="checkbox" checked={!hiddenCols.has(c.key)} onChange={() => toggleColumn(c.key)} />
                    {c.header}
                  </label>
                ))}
              </div>
            </>
          )}
        </div>
      </div>

      {data?.run && (
        <Card>
          <CardContent className="grid grid-cols-2 gap-y-1 p-4 text-sm sm:grid-cols-4">
            <div>
              <div className="text-muted-foreground">Total item</div>
              <div className="font-medium">{data.run.item_count}</div>
            </div>
            <div>
              <div className="text-muted-foreground">Tidak Aman</div>
              <div className="font-medium text-red-700">{data.run.tidak_aman_count}</div>
            </div>
            <div>
              <div className="text-muted-foreground">Threshold Lead Time (p75)</div>
              <div className="font-medium">{data.run.lead_time_threshold} hari</div>
            </div>
            <div>
              <div className="text-muted-foreground">Median Defisit</div>
              <div className="font-medium">{data.run.median_deficit}</div>
            </div>
          </CardContent>
        </Card>
      )}

      <FilterBar>
        <Input
          placeholder="Cari…"
          className="max-w-xs"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
        />
        <select
          className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm"
          value={status}
          onChange={(e) => {
            setStatus(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua status</option>
          <option value="TIDAK_AMAN">Tidak Aman</option>
          <option value="AMAN">Aman</option>
          <option value="BEP">Stok Habis (BEP)</option>
        </select>
        <select
          className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm"
          value={priorityLevel}
          onChange={(e) => {
            setPriorityLevel(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua prioritas</option>
          <option value="HIGH">Tinggi</option>
          <option value="MEDIUM">Sedang</option>
          <option value="LOW">Rendah</option>
        </select>
        <select
          className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm"
          value={category}
          onChange={(e) => {
            setCategory(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua kategori</option>
          {indukOptions.map((v) => (
            <option key={v} value={v}>{v}</option>
          ))}
        </select>
      </FilterBar>

      {isError && <p className="text-sm text-destructive">Gagal memuat analisis.</p>}
      {err && <p className="text-sm text-destructive">{err}</p>}

      {picked.size > 0 && (
        <div className="sticky top-16 z-20 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-primary/40 bg-primary/5 px-4 py-2.5">
          <span className="text-sm"><b>{picked.size}</b> barang dipilih — qty mengikuti saran analisis (dikurangi yang sedang dipesan), bisa diubah di Draft PPB.</span>
          <div className="flex gap-2">
            <Button size="sm" variant="ghost" onClick={() => setPicked(new Map())}><X className="size-4" /> Batal pilih</Button>
            <Button size="sm" disabled={restock.isPending} onClick={() => startRestock([...picked.values()])}>
              <PackagePlus className="size-4" /> {restock.isPending ? 'Memproses…' : `Restock ${picked.size} barang`}
            </Button>
          </div>
        </div>
      )}

      <DataTable
        columns={visibleColumns}
        rows={scrollRows}
        footer={scrollFooter}
        notes={{ entity: 'inventory_item' }} rowKey={(r) => r.item.id}
        isLoading={isLoading}
        emptyText="Belum ada hasil analisis."
      />
    </div>
  )
}

// ============================================================ Tab 2 — Safety Stock

const safetyColumns: Column<SafetyStockRow>[] = [
 { key: 'code', header: 'Kode', cell: (r) => <span className="font-mono text-xs">{r.item_code ?? ''}</span> },
 { key: 'desc', header: 'Deskripsi', cell: (r) => r.item_description ?? '' },
 { key: 'cat', header: 'Kategori Sheet', cell: (r) => r.source_category ?? '' },
 { key: 'avg3', header: 'Rata2 3bln *', cell: (r) => r.avg_usage_3m ?? '' },
 { key: 'lt', header: 'LT (hari)', cell: (r) => r.lead_time_days ?? '' },
  { key: 'ss', header: 'Safety Stock', cell: (r) => <span className="font-medium">{r.safety_stock}</span> },
 { key: 'minpr', header: 'MIN PR', cell: (r) => r.min_pr ?? '' },
  {
    key: 'status',
    header: 'Status',
    cell: (r) => (
      <div className="flex flex-wrap gap-1">
        {r.is_effective ? <Badge variant="success">Efektif</Badge> : <Badge variant="neutral">Non-aktif</Badge>}
        {r.needs_review && <Badge variant="warning">Perlu Review</Badge>}
      </div>
    ),
  },
]

interface SafetyFormState {
  item: ItemLookupResult | null
  itemLabel: string
  sourceCategory: string
  avg1: string
  avg3: string
  avg6: string
  avg12: string
  leadTime: string
  note: string
}

const emptySafetyForm: SafetyFormState = {
  item: null,
  itemLabel: '',
  sourceCategory: '',
  avg1: '',
  avg3: '',
  avg6: '',
  avg12: '',
  leadTime: '',
  note: '',
}

function toSafetyPayload(f: SafetyFormState): Partial<SafetyStockPayload> {
  return {
    ...(f.item ? { item_id: f.item.id } : {}),
    source_category: f.sourceCategory || undefined,
    avg_usage_1m: f.avg1 === '' ? undefined : Number(f.avg1),
    avg_usage_3m: f.avg3 === '' ? undefined : Number(f.avg3),
    avg_usage_6m: f.avg6 === '' ? undefined : Number(f.avg6),
    avg_usage_12m: f.avg12 === '' ? undefined : Number(f.avg12),
    lead_time_days: f.leadTime === '' ? undefined : Number(f.leadTime),
    note: f.note || undefined,
  }
}

function SafetyCreateForm({ onDone }: { onDone: () => void }) {
  const create = useCreateSafetyStock()
  const [form, setForm] = useState<SafetyFormState>(emptySafetyForm)
  const [err, setErr] = useState<string | null>(null)

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        {form.item ? (
          <div className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
            <span>
              <span className="font-mono text-xs text-muted-foreground">{form.item.code}</span> {form.item.description}
            </span>
            <button className="text-xs text-destructive" onClick={() => setForm((f) => ({ ...f, item: null }))}>
              ganti
            </button>
          </div>
        ) : (
          <ItemPicker onPick={(it) => setForm((f) => ({ ...f, item: it }))} />
        )}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
 {/* avg_usage_3m bukan avg_usage_1m yang masuk rumus Safety Stock/MIN PR (lihat SafetyStockService). */}
          <Input
            type="number" step="any" placeholder="Rata2 3 bln *"
            value={form.avg3} onChange={(e) => setForm((f) => ({ ...f, avg3: e.target.value }))}
          />
          <Input
            type="number" min={0} placeholder="Lead time (hari) *"
            value={form.leadTime} onChange={(e) => setForm((f) => ({ ...f, leadTime: e.target.value }))}
          />
          <Input
            placeholder="Kategori sheet (mis. ASSETS)"
            value={form.sourceCategory}
            onChange={(e) => setForm((f) => ({ ...f, sourceCategory: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 1 bln (informasi)"
            value={form.avg1} onChange={(e) => setForm((f) => ({ ...f, avg1: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 6 bln (informasi)"
            value={form.avg6} onChange={(e) => setForm((f) => ({ ...f, avg6: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 12 bln (informasi)"
            value={form.avg12} onChange={(e) => setForm((f) => ({ ...f, avg12: e.target.value }))}
          />
        </div>
        <Input placeholder="Catatan (opsional)" value={form.note} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} />
        <p className="text-xs text-muted-foreground">
          * wajib — Safety Stock dan MIN PR dihitung otomatis dari Rata2 3 bln × Lead Time (rumus yang sama seperti sheet Excel asli). Rata2 1/6/12 bln hanya informasi.
        </p>
        {err && <p className="text-sm text-destructive">{err}</p>}
        <div className="flex justify-end gap-2">
          <Button variant="outline" size="sm" onClick={onDone}>
            Batal
          </Button>
          <Button
            size="sm"
            disabled={create.isPending || !form.item || form.avg3 === '' || form.leadTime === ''}
            onClick={() =>
              create.mutate(toSafetyPayload(form) as SafetyStockPayload, {
                onSuccess: onDone,
                onError: (e) => setErr(apiErrorMessage(e)),
              })
            }
          >
            Simpan
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

function SafetyEditForm({ row, onDone }: { row: SafetyStockRow; onDone: () => void }) {
  const update = useUpdateSafetyStock(row.id)
  const [form, setForm] = useState<SafetyFormState>({
    ...emptySafetyForm,
    sourceCategory: row.source_category ?? '',
    avg1: row.avg_usage_1m?.toString() ?? '',
    avg3: row.avg_usage_3m?.toString() ?? '',
    avg6: row.avg_usage_6m?.toString() ?? '',
    avg12: row.avg_usage_12m?.toString() ?? '',
    leadTime: row.lead_time_days?.toString() ?? '',
    note: row.note ?? '',
  })
  const [err, setErr] = useState<string | null>(null)

  return (
    <Card className="border-primary/30 bg-muted/30">
      <CardContent className="space-y-3 p-4">
        <div className="text-sm">
          <span className="font-mono text-xs text-muted-foreground">{row.item_code}</span> {row.item_description}
        </div>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <Input
            type="number" step="any" placeholder="Rata2 3 bln *"
            value={form.avg3} onChange={(e) => setForm((f) => ({ ...f, avg3: e.target.value }))}
          />
          <Input
            type="number" min={0} placeholder="Lead time (hari) *"
            value={form.leadTime} onChange={(e) => setForm((f) => ({ ...f, leadTime: e.target.value }))}
          />
          <Input
            placeholder="Kategori sheet"
            value={form.sourceCategory} onChange={(e) => setForm((f) => ({ ...f, sourceCategory: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 1 bln (informasi)"
            value={form.avg1} onChange={(e) => setForm((f) => ({ ...f, avg1: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 6 bln (informasi)"
            value={form.avg6} onChange={(e) => setForm((f) => ({ ...f, avg6: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 12 bln (informasi)"
            value={form.avg12} onChange={(e) => setForm((f) => ({ ...f, avg12: e.target.value }))}
          />
        </div>
        <Input placeholder="Catatan" value={form.note} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} />
        {err && <p className="text-sm text-destructive">{err}</p>}
        <div className="flex justify-end gap-2">
          <Button variant="outline" size="sm" onClick={onDone}>
            Batal
          </Button>
          <Button
            size="sm"
            disabled={update.isPending}
            onClick={() =>
              update.mutate(toSafetyPayload(form), { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) })
            }
          >
            Simpan
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

/** Safety Stock — input manual rata-rata pemakaian & lead time; Safety Stock/MIN PR dihitung otomatis. */
function SafetyStockTab() {
  const { hasPermission } = useAuth()
  const [search, setSearch] = useState('')
  const [needsReview, setNeedsReview] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useSafetyStockList({
    search: search || undefined,
    needs_review: needsReview ? (needsReview as '0' | '1') : undefined,
    page,
  })
  // scroll to the end instead of Sebelumnya/Berikutnya (pages appended while scrolling)
  const { rows: scrollRows, footer: scrollFooter } = useInfiniteRows(data, page, setPage)

  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const del = useDeleteSafetyStock()
  const resolve = useResolveSafetyStockConflict()
  const [err, setErr] = useState<string | null>(null)

  const canUpdate = hasPermission('item.safety_stock.update')
  const canResolve = hasPermission('item.safety_stock.resolve_conflict')

  async function remove(row: SafetyStockRow) {
    if (!(await confirmDialog(`Hapus safety stock untuk ${row.item_code}?`))) return
    del.mutate(row.id, { onError: (e) => setErr(apiErrorMessage(e)) })
  }

  const editingRow = editingId != null ? scrollRows.find((r) => r.id === editingId) : undefined

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-end gap-2">
        {canUpdate && (
          <Button size="sm" variant="outline" onClick={() => setShowForm((v) => !v)}>
            {showForm ? 'Tutup' : 'Tambah Safety Stock'}
          </Button>
        )}
      </div>

      {showForm && <SafetyCreateForm onDone={() => setShowForm(false)} />}
      {editingRow && <SafetyEditForm row={editingRow} onDone={() => setEditingId(null)} />}

      <div className="flex flex-wrap gap-2">
        <Input
          placeholder="Cari kode / deskripsi barang…"
          className="max-w-xs"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
        />
        <select
          className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm"
          value={needsReview}
          onChange={(e) => {
            setNeedsReview(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua baris</option>
          <option value="1">Perlu review (konflik)</option>
          <option value="0">Efektif saja</option>
        </select>
      </div>

      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable
        columns={
          canUpdate || canResolve
            ? [
                ...safetyColumns,
                {
                  key: 'actions',
                  header: '',
                  cell: (r) => (
                    <div className="flex justify-end gap-1">
                      {canResolve && r.needs_review && !r.is_effective && (
                        <Button size="sm" variant="outline" onClick={() => resolve.mutate(r.id, { onError: (e) => setErr(apiErrorMessage(e)) })}>
                          Jadikan Efektif
                        </Button>
                      )}
                      {canUpdate && (
                        <>
                          <Button size="sm" variant="ghost" onClick={() => setEditingId(r.id)}>
                            <Pencil className="size-4" />
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => remove(r)} disabled={del.isPending}>
                            <Trash2 className="size-4" />
                          </Button>
                        </>
                      )}
                    </div>
                  ),
                },
              ]
            : safetyColumns
        }
        rows={scrollRows}
        footer={scrollFooter}
        notes={{ entity: 'safety_stock' }} rowKey={(r) => r.id}
        isLoading={isLoading}
      />
    </div>
  )
}

// ============================================================ Halaman gabungan

const TOP_TABS = [
  { key: 'analysis' as const, label: 'Analisis Inventory', icon: Boxes, permission: 'inventory.view_analysis' },
  { key: 'safety' as const, label: 'Safety Stock', icon: ShieldCheck, permission: 'item.safety_stock.view' },
]

/**
 * Analisis Inventory + Safety Stock dalam satu halaman — Safety Stock adalah input
 * langsung ke rumus Analisis (InventoryAnalyzer memakai item_safety_stocks.safety_stock
 * yang efektif), jadi keduanya digabung supaya mengubah Safety Stock dan melihat
 * dampaknya ke status/prioritas tidak perlu pindah halaman.
 */
export function InventoryHealthPage() {
  const { hasPermission } = useAuth()
  const [params] = useSearchParams()
  const tabs = TOP_TABS.filter((t) => hasPermission(t.permission))
  const requested = params.get('tab')
  const initial = tabs.find((t) => t.key === requested)?.key ?? tabs[0]?.key ?? 'analysis'
  const [tab, setTab] = useState<(typeof TOP_TABS)[number]['key']>(initial)

  return (
    <div className="space-y-4">
      <PageHeader
        title="Analisis Inventory & Safety Stock"
        subtitle="Status stok tiap barang terhadap batas amannya, dan input Safety Stock/lead time di balik perhitungan itu"
        icon={<Boxes className="size-5" />}
      />

      {tabs.length > 1 && (
        <div className="inline-flex rounded-md border bg-muted/30 p-1">
          {tabs.map(({ key, label, icon: Icon }) => (
            <button
              key={key}
              onClick={() => setTab(key)}
              className={`inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5 text-sm font-medium transition ${
                tab === key ? 'bg-card shadow-sm' : 'text-muted-foreground hover:text-foreground'
              }`}
            >
              <Icon className="size-4" /> {label}
            </button>
          ))}
        </div>
      )}

      {tab === 'analysis' ? <AnalysisTab /> : <SafetyStockTab />}
    </div>
  )
}
