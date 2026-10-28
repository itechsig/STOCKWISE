import { useEffect, useRef, useState } from 'react'
import { PpbApprovalBadge } from '@/components/PpbApproval'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { usePoDivisiOptions, usePoList, useVendors, type PoListRow } from '@/features/purchasing/api'
import { Plus, Search, ShoppingCart, X } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { PoFormModal } from '@/components/PoFormModal'
import { ExportBar } from '@/components/export/ExportBar'
import { PageHeader } from '@/components/PageHeader'
import { AccurateDataNote } from '@/components/AccurateDataNote'
import { ColumnMenu, useHiddenColumns, visibleColumns } from '@/components/ColumnVisibility'
import { DataTable, type Column } from '@/components/DataTable'
import { DocCell, DocText } from '@/components/DocLink'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { RequestStatusBadge, statusLabel } from '@/components/ui/request-badge'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { useRemembered } from '@/lib/listMemory'
import { FilterBar } from '@/components/FilterBar'
import { QtyProgress } from '@/components/QtyProgress'

const fmtDate = (v: string | null | undefined) => (v ? new Date(v).toLocaleDateString('id-ID') : '—')
const ALWAYS_VISIBLE = ['number']

/**
 * Satu baris per barang (seperti PPB/RI) — PO dengan beberapa barang tampil
 * sebagai beberapa baris senomor PO yang sama, bukan satu baris dengan semua
 * kode/deskripsinya ditumpuk jadi satu sel. Urutan baca: identitas PO dulu
 * (nomor+tanggal), lalu dengan siapa (vendor) dan beli apa (barang+qty), baru
 * status baris & rantai dokumennya (PPB -> RI), dan catatan di akhir.
 */
const columns: Column<PoListRow>[] = [
  {
    key: 'number',
    header: 'Nomor PO',
    cell: (r) => (
      <DocCell date={fmtDate(r.po_date)}>
        <span className="inline-flex items-center gap-1.5">
          <Link to={`/purchase-orders/${r.po_id}`} onClick={(e) => e.stopPropagation()} className="font-mono text-xs font-medium text-primary hover:underline">
            {r.po_number}
          </Link>
          {r.is_manual && <Badge variant="neutral">Manual</Badge>}
        </span>
      </DocCell>
    ),
  },
  { key: 'vendor', header: 'Vendor', cell: (r) => <span className="whitespace-nowrap">{r.vendor_name ?? '—'}</span> },
  {
    key: 'barang',
    header: 'Barang',
    className: 'max-w-[260px]',
    cell: (r) => (
      <div className="leading-tight">
        <div className="truncate font-mono text-xs text-muted-foreground">{r.item_code ?? '—'}</div>
        <div className="truncate" title={r.description ?? undefined}>{r.description ?? '—'}</div>
      </div>
    ),
  },
  { key: 'qty', header: 'Qty', className: 'text-right', cell: (r) => <span className="whitespace-nowrap tabular-nums">{r.qty} {r.unit ?? ''}</span> },
  // ordered / received so far (RI): a partial delivery reads "10/5"
  { key: 'diterima', header: 'Dipesan/Diterima', cell: (r) => <QtyProgress total={r.qty} done={r.received} unit={r.unit} /> },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
  {
    key: 'ppb',
    header: 'PPB',
    cell: (r) => (
      <DocCell date={fmtDate(r.ppb_date)}>
        <DocText text={r.ppb_no} className="font-mono text-xs" />
        {r.ppb_approval?.po_without_approval && <span className="mt-0.5 block"><PpbApprovalBadge approval={r.ppb_approval} /></span>}
      </DocCell>
    ),
  },
  {
    key: 'ri',
    header: 'RI',
    cell: (r) => <DocCell date={fmtDate(r.ri_date)}><DocText text={r.ri_no} className="font-mono text-xs" /></DocCell>,
  },
  { key: 'divisi', header: 'Divisi', cell: (r) => <span className="whitespace-nowrap">{r.divisi ?? '—'}</span> },
  {
    key: 'keterangan',
    header: 'Keterangan',
    className: 'max-w-[220px]',
    cell: (r) => <span className="block truncate" title={r.keterangan ?? undefined}>{r.keterangan ?? '—'}</span>,
  },
]

export function PoListPage() {
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const [formOpen, setFormOpen] = useState(false)
  // ?search=<no. dokumen> — opened from a document link elsewhere (DocLink)
  const [params] = useSearchParams()
  const urlSearch = params.get('search') ?? ''
  const [search, setSearch] = useRemembered('purchase-orders', 'search', urlSearch)
  const [status, setStatus] = useRemembered('purchase-orders', 'status', '')
  const [vendorId, setVendorId] = useRemembered('purchase-orders', 'vendorId', '')
  const [divisi, setDivisi] = useRemembered('purchase-orders', 'divisi', '')
  const [dateFrom, setDateFrom] = useRemembered('purchase-orders', 'dateFrom', '')
  const [dateTo, setDateTo] = useRemembered('purchase-orders', 'dateTo', '')
  const [source, setSource] = useRemembered('purchase-orders', 'source', '')
  const [page, setPage] = useRemembered('purchase-orders', 'page', 1)
  // first render: the state above already starts from the URL (or is restored after Back);
  // only later URL changes (a document link opened while here) reset the filters
  const lastUrl = useRef([urlSearch].join('|'))
  useEffect(() => {
    const now = [urlSearch].join('|')
    if (now === lastUrl.current) return
    lastUrl.current = now
    setSearch(urlSearch)
    setPage(1)
  }, [urlSearch])
  const activeFilters = {
    search: search.trim() || undefined, status: status || undefined,
    vendor_id: vendorId || undefined, divisi: divisi || undefined,
    date_from: dateFrom || undefined, date_to: dateTo || undefined, source: source || undefined,
  }
  const { data, isLoading } = usePoList({ ...activeFilters, page })
  const { data: vendors } = useVendors()
  const { data: divisiOptions } = usePoDivisiOptions()
  // every filter change starts again from the first page
  const resetPage = <T,>(fn: (v: T) => void) => (v: T) => { fn(v); setPage(1) }
  const hasFilters = !!(search || status || vendorId || divisi || dateFrom || dateTo || source)
  const clearFilters = () => { setSearch(''); setStatus(''); setVendorId(''); setDivisi(''); setDateFrom(''); setDateTo(''); setSource(''); setPage(1) }
  // scroll to the end instead of Sebelumnya/Berikutnya (pages appended while scrolling)
  const { rows: scrollRows, footer: scrollFooter } = useInfiniteRows(data, page, setPage, 'purchase-orders')

  const [hiddenCols, toggleCol] = useHiddenColumns('stockwise:po-hidden-columns')
  const shownColumns = visibleColumns(columns, hiddenCols, ALWAYS_VISIBLE)
  const visibleKeys = shownColumns.map((c) => c.key).join(',')

  return (
    <div className="space-y-4">
      <PageHeader
        title="Purchase Order"
        subtitle="Pesanan pembelian barang ke vendor"
        icon={<ShoppingCart className="size-5" />}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ExportBar dataset="purchase-orders" filters={activeFilters} filenameBase="purchase-order" viewPermission="po.view" columns={visibleKeys} />
            {hasPermission('po.create') && (
              <Button size="sm" onClick={() => setFormOpen(true)}>
                <Plus className="size-4" />
                Tambah PO
              </Button>
            )}
          </div>
        }
      />
      <AccurateDataNote className="-mt-2" />

      <FilterBar className="grid gap-2 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-end" end={<>
        {data && <span className="text-xs text-muted-foreground">{data.meta.total.toLocaleString('id-ID')} barang</span>}
        <ColumnMenu columns={columns} hiddenCols={hiddenCols} onToggle={toggleCol} alwaysVisible={ALWAYS_VISIBLE} />
      </>}>
        <div className="relative sm:col-span-2 lg:w-80">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Cari no. PO, vendor, barang, no. PPB / RI, divisi, keterangan…"
            title="Bisa beberapa kata sekaligus, mis. “sinar kabel” semua kata harus cocok, ke kolom mana saja"
            className="pl-9"
            value={search}
            onChange={(e) => resetPage(setSearch)(e.target.value)}
          />
        </div>
        <div className="lg:w-44">
          <Select value={status} onChange={(e) => resetPage(setStatus)(e.target.value)} aria-label="Status">
            <option value="">Semua status</option>
            {['MENUNGGU_PO', 'PENDING', 'DIBELI', 'PARTIAL_RECEIVED', 'RECEIVED', 'CLOSED'].map((s) => (
              <option key={s} value={s}>{statusLabel(s)}</option>
            ))}
          </Select>
        </div>
        <div className="lg:w-48">
          <Select value={vendorId} onChange={(e) => resetPage(setVendorId)(e.target.value)} aria-label="Vendor">
            <option value="">Semua vendor</option>
            {vendors?.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
          </Select>
        </div>
        <div className="lg:w-44">
          <Select value={divisi} onChange={(e) => resetPage(setDivisi)(e.target.value)} aria-label="Divisi">
            <option value="">Semua divisi</option>
            {divisiOptions?.map((d) => <option key={d} value={d}>{d}</option>)}
          </Select>
        </div>
        <div className="lg:w-36">
          <Select value={source} onChange={(e) => resetPage(setSource)(e.target.value)} aria-label="Sumber">
            <option value="">Semua sumber</option>
            <option value="accurate">Accurate</option>
            <option value="internal">Internal</option>
          </Select>
        </div>
        <label className="space-y-1 text-xs text-muted-foreground">
          Dari tanggal
          <Input type="date" value={dateFrom} max={dateTo || undefined} onChange={(e) => resetPage(setDateFrom)(e.target.value)} />
        </label>
        <label className="space-y-1 text-xs text-muted-foreground">
          Sampai tanggal
          <Input type="date" value={dateTo} min={dateFrom || undefined} onChange={(e) => resetPage(setDateTo)(e.target.value)} />
        </label>
        {hasFilters && (
          <Button variant="ghost" size="sm" className="h-10" onClick={clearFilters}>
            <X className="size-4" /> Reset filter
          </Button>
        )}
      </FilterBar>

      <DataTable
        columns={shownColumns}
        rows={scrollRows} scrollMemoryKey="purchase-orders"
        footer={scrollFooter}
        notes={{ entity: 'po', key: (r) => r.po_id }}
        rowKey={(r) => r.id}
        isLoading={isLoading}
        onRowClick={(r) => navigate(`/purchase-orders/${r.po_id}`)}
        emptyText={hasFilters ? 'Tidak ada barang PO untuk filter ini.' : 'Belum ada data PO.'}
      />

      <PoFormModal open={formOpen} onClose={() => setFormOpen(false)} />
    </div>
  )
}
