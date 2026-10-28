import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Columns3, Eye, Package, Pencil, Trash2, Plus } from 'lucide-react'
import { useAccurateCategoryOptions, useDeleteItem, useItemOptions, useItems, useUnits, useWarehouses } from '@/features/inventory/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { ItemFormModal } from '@/components/ItemFormModal'
import { ItemHistoryModal } from '@/components/ItemHistoryModal'
import { ExportBar } from '@/components/export/ExportBar'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, type Column } from '@/components/DataTable'
import { Badge, PriorityBadge, StatusBadge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { Item } from '@/types/inventory'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { confirmDialog } from '@/components/ui/confirm'
import { FilterBar } from '@/components/FilterBar'

const HIDDEN_COLUMNS_STORAGE_KEY = 'stockwise:items-hidden-columns'

function loadHiddenColumns(): Set<string> {
  try {
    const raw = localStorage.getItem(HIDDEN_COLUMNS_STORAGE_KEY)
    return raw ? new Set(JSON.parse(raw) as string[]) : new Set()
  } catch {
    return new Set()
  }
}

export function ItemsPage() {
  const { hasPermission } = useAuth()
  // ?search=<kode> — opened from a link elsewhere (e.g. Hasil Produk in Manufaktur)
  const [params] = useSearchParams()
  const urlSearch = params.get('search') ?? ''
  // ?kind=BARANG|JASA — the sidebar's Master Produk › Barang / Jasa
  const [, setParams] = useSearchParams()
  const kind = (params.get('kind') ?? '').toUpperCase()
  const setKind = (k: string) => setParams((p) => { const n = new URLSearchParams(p); if (k) n.set('kind', k); else n.delete('kind'); return n }, { replace: true })
  const [search, setSearch] = useState(urlSearch)
  useEffect(() => setSearch(urlSearch), [urlSearch])
  const [status, setStatus] = useState('')
  const [induk, setInduk] = useState('')
  const [anak1, setAnak1] = useState('')
  const [anak2, setAnak2] = useState('')
  const [anak3, setAnak3] = useState('')
  const [unitId, setUnitId] = useState('')
  const [warehouseId, setWarehouseId] = useState('')
  const [page, setPage] = useState(1)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Item | null>(null)
  const [historyItem, setHistoryItem] = useState<Item | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const del = useDeleteItem()
  const { data: branches } = useAccurateCategoryOptions()
  const { data: units } = useUnits()
  const { data: warehouses } = useWarehouses()

  // Filter kategori bertingkat dibangun client-side dari daftar cabang nyata
  // (accurate_category_anak_1/2/3) — sama seperti CategoryPicker.tsx untuk
  // tree Excel. Kategori Induk BUKAN bagian dari tingkatan anak_1/2/3 (itu
  // diturunkan dari 3 huruf depan kode barang, sistem klasifikasi yang
  // berbeda) — jadi dropdown-nya independen, tidak cascading ke anak_1/2/3.
  // the fixed Kategori Induk list (same as the item form), plus any other value the data still has
  const { data: itemOptions } = useItemOptions()
  const indukOptions = useMemo(() => {
    const fixed = itemOptions?.induk ?? []
    const extra = [...new Set(branches?.map((b) => b.accurate_category_induk).filter((v): v is string => v != null && !fixed.includes(v)) ?? [])].sort()
    return [...fixed, ...extra]
  }, [branches, itemOptions])
  const anak1Options = useMemo(
    () => [...new Set(branches?.map((b) => b.accurate_category_anak_1).filter((v): v is string => v != null) ?? [])].sort(),
    [branches]
  )
  const anak2Options = useMemo(
    () =>
      [
        ...new Set(
          branches?.filter((b) => b.accurate_category_anak_1 === anak1).map((b) => b.accurate_category_anak_2) ?? []
        ),
      ].filter((v): v is string => v != null).sort(),
    [branches, anak1]
  )
  const anak3Options = useMemo(
    () =>
      [
        ...new Set(
          branches
            ?.filter((b) => b.accurate_category_anak_1 === anak1 && b.accurate_category_anak_2 === anak2)
            .map((b) => b.accurate_category_anak_3) ?? []
        ),
      ].filter((v): v is string => v != null).sort(),
    [branches, anak1, anak2]
  )

  function pickAnak1(v: string) {
    setAnak1(v)
    setAnak2('')
    setAnak3('')
    setPage(1)
  }

  function pickAnak2(v: string) {
    setAnak2(v)
    setAnak3('')
    setPage(1)
  }

  const activeFilters = {
    search: search || undefined,
    product_kind: kind || undefined,
    status: status || undefined,
    accurate_category_induk: induk || undefined,
    accurate_category_anak_1: anak1 || undefined,
    accurate_category_anak_2: anak2 || undefined,
    accurate_category_anak_3: anak3 || undefined,
    unit_id: unitId ? Number(unitId) : undefined,
    warehouse_id: warehouseId ? Number(warehouseId) : undefined,
  }

  const { data, isLoading, isError } = useItems({ ...activeFilters, page, per_page: 25 })
  // scroll to the end instead of Sebelumnya/Berikutnya (pages appended while scrolling)
  const { rows: scrollRows, footer: scrollFooter } = useInfiniteRows(data, page, setPage)

  const canEdit = hasPermission('item.update')
  const canDelete = hasPermission('item.delete')

  function openCreate() {
    setEditing(null)
    setFormOpen(true)
  }

  function openEdit(item: Item) {
    setEditing(item)
    setFormOpen(true)
  }

  useEffect(() => setPage(1), [kind])

  async function remove(item: Item) {
    if (!(await confirmDialog(`Hapus produk ${item.code} — ${item.description}?`))) return
    setErr(null)
    del.mutate(item.id, { onError: (e) => setErr(apiErrorMessage(e)) })
  }

  const columns: Column<Item>[] = [
    {
      key: 'code',
      header: 'Kode Produk',
      // Menempel (sticky) di kiri — tetap terlihat tanpa harus geser tabel ke
      // kanan, supaya kode barang & ikon histori selalu kelihatan di antara
      // banyak kolom kategori/analisis di sebelah kanannya.
      className: 'sticky left-0 z-10 whitespace-nowrap border-r bg-muted',
      cell: (r) => (
        <div className="flex items-center gap-2">
          <span className="font-mono text-xs">{r.code}</span>
          <button
            className="text-muted-foreground hover:text-primary"
            onClick={(e) => { e.stopPropagation(); setHistoryItem(r) }}
            title="Lihat histori barang"
            aria-label="Lihat histori barang"
          >
            <Eye className="size-4" />
          </button>
        </div>
      ),
    },
    {
      key: 'kind',
      header: 'Jenis',
      className: 'whitespace-nowrap',
      cell: (r) => <Badge variant={r.product_kind === 'JASA' ? 'warning' : 'neutral'}>{r.product_kind === 'JASA' ? 'Jasa' : 'Barang'}</Badge>,
    },
    { key: 'induk', header: 'Kategori Induk', className: 'whitespace-nowrap', cell: (r) => r.category_breakdown?.induk ?? '—' },
    { key: 'anak1', header: 'Kategori Anak 1', className: 'whitespace-nowrap', cell: (r) => r.category_breakdown?.anak_1 ?? '—' },
    { key: 'anak2', header: 'Kategori Anak 2', className: 'whitespace-nowrap', cell: (r) => r.category_breakdown?.anak_2 ?? '—' },
    { key: 'anak3', header: 'Kategori Anak 3', className: 'whitespace-nowrap', cell: (r) => r.category_breakdown?.anak_3 ?? '—' },
    { key: 'description', header: 'Deskripsi Produk', className: 'whitespace-nowrap', cell: (r) => r.description },
    { key: 'unit', header: 'UOM', className: 'whitespace-nowrap', cell: (r) => r.unit?.code ?? '—' },
    {
      key: 'qty',
      header: 'QTY',
      className: 'whitespace-nowrap',
      cell: (r) => (r.accurate_qty_onhand != null ? <span className="tabular-nums">{r.accurate_qty_onhand}</span> : '—'),
    },
    {
      key: 'forecast',
      header: 'Forecast',
      className: 'whitespace-nowrap',
      cell: (r) => {
        const onOrder = r.accurate_qty_onorder ?? 0
        if (r.forecast_qty == null) return '—'
        return (
          <span className="tabular-nums" title={onOrder > 0 ? `QTY ${r.accurate_qty_onhand ?? 0} + PO ${onOrder}` : undefined}>
            {r.forecast_qty}
            {onOrder > 0 && <span className="ml-1 text-xs text-muted-foreground">(+{onOrder} PO)</span>}
          </span>
        )
      },
    },
    {
      key: 'blueprint',
      header: 'Perlu Blueprint?',
      className: 'whitespace-nowrap',
      cell: (r) => <Badge variant={r.needs_blueprint ? 'warning' : 'neutral'}>{r.needs_blueprint ? 'Ya' : 'Tidak'}</Badge>,
    },
    { key: 'alias', header: 'Nama Alias', className: 'whitespace-nowrap', cell: (r) => r.alias_name ?? '—' },
    { key: 'gudang', header: 'Letak Gudang', className: 'whitespace-nowrap', cell: (r) => r.default_warehouse?.code ?? '—' },
    { key: 'rak', header: 'Letak Rak', className: 'whitespace-nowrap', cell: (r) => r.default_location?.code ?? '—' },
    {
      key: 'blueprint_img',
      header: 'Blueprint IMG',
      className: 'whitespace-nowrap',
      cell: (r) => (r.blueprint_img_path ? <span className="font-mono text-xs">{r.blueprint_img_path}</span> : '—'),
    },
    {
      key: 'blueprint_pdf',
      header: 'Blueprint Detail PDF',
      className: 'whitespace-nowrap',
      cell: (r) => (r.blueprint_pdf_path ? <span className="font-mono text-xs">{r.blueprint_pdf_path}</span> : '—'),
    },
    { key: 'lt', header: 'Lead Time', className: 'whitespace-nowrap', cell: (r) => (r.lead_time_days != null ? `${r.lead_time_days} hr` : '—') },
    {
      key: 'available',
      header: 'Tersedia',
      className: 'whitespace-nowrap',
      cell: (r) =>
        r.analysis
          ? r.analysis.stock_known
            ? r.analysis.available
            : <span className="text-muted-foreground">UNKNOWN</span>
          : '—',
    },
    { key: 'ss', header: 'Safety Stock', className: 'whitespace-nowrap', cell: (r) => r.analysis?.safety_stock ?? r.safety_stock ?? '—' },
    { key: 'min_pr', header: 'MIN PR', className: 'whitespace-nowrap', cell: (r) => r.min_pr ?? '—' },
    { key: 'status', header: 'Status', className: 'whitespace-nowrap', cell: (r) => (r.analysis ? <StatusBadge status={r.analysis.status} /> : '—') },
    { key: 'priority', header: 'Prioritas', className: 'whitespace-nowrap', cell: (r) => (r.analysis ? <PriorityBadge level={r.analysis.priority_level} /> : '—') },
    ...(canEdit || canDelete
      ? [
          {
            key: 'actions',
            header: '',
            cell: (r: Item) => (
              <div className="flex items-center gap-2">
                {canEdit && (
                  <button className="text-muted-foreground hover:text-primary" onClick={() => openEdit(r)} aria-label="Ubah">
                    <Pencil className="size-4" />
                  </button>
                )}
                {canDelete && (
                  <button className="text-muted-foreground hover:text-destructive" onClick={() => remove(r)} aria-label="Hapus">
                    <Trash2 className="size-4" />
                  </button>
                )}
              </div>
            ),
          } satisfies Column<Item>,
        ]
      : []),
  ]

  const [hiddenCols, setHiddenCols] = useState<Set<string>>(loadHiddenColumns)
  const [colMenuOpen, setColMenuOpen] = useState(false)

  useEffect(() => {
    try {
      localStorage.setItem(HIDDEN_COLUMNS_STORAGE_KEY, JSON.stringify([...hiddenCols]))
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

  // Kode Barang, Histori & kolom aksi selalu tampil — sisanya bisa disembunyikan.
  const ALWAYS_VISIBLE = ['code', 'history', 'actions']
  const toggleableColumns = columns.filter((c) => !ALWAYS_VISIBLE.includes(c.key))
  const visibleColumns = columns.filter((c) => ALWAYS_VISIBLE.includes(c.key) || !hiddenCols.has(c.key))

  return (
    <div className="space-y-5">
      <PageHeader
        title={kind === 'JASA' ? 'Master Data · Jasa' : kind === 'BARANG' ? 'Master Data · Barang' : 'Master Data · Barang & Jasa'}
        subtitle={`${data ? `${data.meta.total.toLocaleString('id-ID')} produk` : 'Memuat…'} — barang (punya stok) dan jasa, beserta kategorinya`}
        icon={<Package className="size-5" />}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ExportBar dataset="items" filters={activeFilters} filenameBase="master-produk" viewPermission="report.items" />
            {hasPermission('item.create') && (
              <Button size="sm" onClick={openCreate}>
                <Plus className="size-4" />
                Tambah Produk
              </Button>
            )}
          </div>
        }
      />

      {/* Semua / Barang / Jasa */}
      <div className="inline-flex rounded-lg border bg-card p-0.5" role="tablist" aria-label="Jenis produk">
        {([['', 'Semua'], ['BARANG', 'Barang'], ['JASA', 'Jasa']] as const).map(([k, label]) => (
          <button key={k || 'all'} type="button" role="tab" aria-selected={kind === k} onClick={() => setKind(k)}
            className={kind === k ? 'rounded-md bg-primary/10 px-4 py-1.5 text-sm font-medium text-primary' : 'rounded-md px-4 py-1.5 text-sm text-muted-foreground hover:bg-muted hover:text-foreground'}>
            {label}
          </button>
        ))}
      </div>

      {/* the column picker stays in sight on a phone too (not folded behind "Filter") */}
      <FilterBar end={
        <div className="relative">
          <Button size="sm" variant="outline" onClick={() => setColMenuOpen((v) => !v)}>
            <Columns3 className="size-4" />
            Kolom
          </Button>
          {colMenuOpen && (
            <>
              <div className="fixed inset-0 z-10" onClick={() => setColMenuOpen(false)} />
              <div className="absolute right-0 z-20 mt-1 max-h-80 w-64 overflow-y-auto rounded-lg border bg-card p-1.5 shadow-lg">
                {toggleableColumns.map((c) => (
                  <label key={c.key} className="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted/60">
                    <input
                      type="checkbox"
                      checked={!hiddenCols.has(c.key)}
                      onChange={() => toggleColumn(c.key)}
                    />
                    {c.header}
                  </label>
                ))}
              </div>
            </>
          )}
        </div>
      }>
        <Input
          placeholder="Cari kode / deskripsi…"
          className="max-w-xs"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
        />
        <select
          className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm"
          value={induk}
          onChange={(e) => {
            setInduk(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua Kategori Induk</option>
          {indukOptions.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
        <select
          className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm"
          value={anak1}
          onChange={(e) => pickAnak1(e.target.value)}
        >
          <option value="">Semua Kategori Anak 1</option>
          {anak1Options.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
        <select
          className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm disabled:bg-muted disabled:text-muted-foreground"
          value={anak2}
          disabled={!anak1 || anak2Options.length === 0}
          onChange={(e) => pickAnak2(e.target.value)}
        >
          <option value="">Semua Kategori Anak 2</option>
          {anak2Options.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
        <select
          className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm disabled:bg-muted disabled:text-muted-foreground"
          value={anak3}
          disabled={!anak2 || anak3Options.length === 0}
          onChange={(e) => {
            setAnak3(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua Kategori Anak 3</option>
          {anak3Options.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
        <select
          className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm"
          value={unitId}
          onChange={(e) => {
            setUnitId(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua UOM</option>
          {units?.map((u) => (
            <option key={u.id} value={u.id}>
              {u.code}
            </option>
          ))}
        </select>
        <select
          className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm"
          value={warehouseId}
          onChange={(e) => {
            setWarehouseId(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua Gudang</option>
          {warehouses?.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
        <select
          className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm"
          value={status}
          onChange={(e) => {
            setStatus(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua status</option>
          <option value="AMAN">Aman</option>
          <option value="TIDAK_AMAN">Tidak Aman</option>
          <option value="BEP">Stok Habis (BEP)</option>
        </select>
      </FilterBar>

      {isError && <p className="text-sm text-destructive">Gagal memuat data.</p>}
      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable
        columns={visibleColumns}
        rows={scrollRows}
        notes={{ entity: 'item' }} rowKey={(r) => r.id}
        isLoading={isLoading}
        containerClassName="max-h-[65vh] overflow-y-auto"
        stickyHeader
        footer={scrollFooter}
      />

      <ItemFormModal open={formOpen} onClose={() => setFormOpen(false)} item={editing} />
      {historyItem && (
        <ItemHistoryModal
          itemId={historyItem.id}
          itemCode={historyItem.code}
          itemDescription={historyItem.description}
          onClose={() => setHistoryItem(null)}
        />
      )}
    </div>
  )
}
