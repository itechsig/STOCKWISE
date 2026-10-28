import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Plus, Truck, X } from 'lucide-react'
import { RI_SOURCE, useRiList, useRiVendorOptions, riJenis, type Ri } from '@/features/ri/api'
import { lokasiText } from '@/features/npbg/api'
import { useAuth } from '@/auth/AuthContext'
import { RiFormModal } from '@/components/RiFormModal'
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
import { useInfiniteRows } from '@/components/InfiniteRows'
import { useRemembered } from '@/lib/listMemory'
import { FilterBar } from '@/components/FilterBar'

function fmtDate(v: string | null | undefined) {
  return v ? new Date(v).toLocaleDateString('id-ID') : '—'
}

function fmtMoney(v: number | null) {
  return v != null ? `Rp ${v.toLocaleString('id-ID')}` : '—'
}

const ALWAYS_VISIBLE = ['no_ri']
/** Jenis RI (middle segment of the RI number) — a small, stable Accurate enum, not evolving master data like vendor/divisi. */
const JENIS_RI = ['NA', 'ATK', 'NV']

/**
 * Kode + deskripsi barang digabung satu sel, dan rantai dokumen PPB -> PO
 * dipasangkan dengan tanggalnya masing-masing — tabel tetap ringkas (13 kolom,
 * bukan 17) tanpa kehilangan satu pun data yang sebelumnya ada.
 */
const columns: Column<Ri>[] = [
  {
    key: 'no_ri',
    header: 'No RI',
    cell: (r) => (
      <DocCell date={fmtDate(r.tgl_ri)}>
        <span className="inline-flex items-center gap-1.5">
          <Link to={`/ri/${r.id}`} onClick={(e) => e.stopPropagation()} className="font-mono text-xs font-medium text-primary hover:underline">
            {r.no_ri ?? '—'}
          </Link>
          {r.is_manual && <Badge variant="neutral">Manual</Badge>}
        </span>
      </DocCell>
    ),
  },
  { key: 'sj', header: 'No. Surat Jalan', cell: (r) => <span className="whitespace-nowrap font-mono text-xs">{r.no_surat_jalan ?? '—'}</span> },
  { key: 'divisi', header: 'Jenis RI', cell: (r) => <span className="whitespace-nowrap">{riJenis(r.divisi)}</span> },
  {
    key: 'sumber',
    header: 'Sumber',
    cell: (r) => r.sumber
      ? <Badge variant={r.sumber === 'BEKAS' ? 'warning' : r.sumber === 'PO' ? 'default' : 'neutral'} className="whitespace-nowrap">{RI_SOURCE[r.sumber]}</Badge>
      : '—',
  },
  { key: 'vendor', header: 'Vendor', cell: (r) => <span className="whitespace-nowrap">{r.vendor ?? '—'}</span> },
  {
    key: 'barang',
    header: 'Barang',
    className: 'max-w-[260px]',
    cell: (r) => (
      <div className="leading-tight">
        <div className="truncate font-mono text-xs text-muted-foreground">{r.kode_barang ?? '—'}</div>
        <div className="truncate" title={r.deskripsi_barang ?? undefined}>{r.deskripsi_barang ?? '—'}</div>
      </div>
    ),
  },
  { key: 'qty', header: 'Kuantitas', className: 'text-right', cell: (r) => <span className="whitespace-nowrap tabular-nums">{r.kuantitas != null ? `${r.kuantitas} ${r.satuan ?? ''}` : '—'}</span> },
  { key: 'harga', header: 'Harga Satuan', className: 'text-right', cell: (r) => <span className="whitespace-nowrap tabular-nums">{fmtMoney(r.harga_satuan)}</span> },
  // Rantai dokumen lengkap PPB -> PO, dikelompokkan sebagai dua sel (bukan
  // empat kolom) di antara data barang dan data pemeriksaan/lokasi.
  { key: 'ppb', header: 'PPB', cell: (r) => <DocCell date={fmtDate(r.tgl_ppb)}><DocText text={r.no_ppb} className="font-mono text-xs" /></DocCell> },
  {
    key: 'po',
    header: 'PO',
    cell: (r) => (
      <DocCell date={fmtDate(r.source_po?.date)}>
        {r.source_po ? (
          <Link to={`/purchase-orders/${r.source_po.purchase_order_id}`} onClick={(e) => e.stopPropagation()} className="font-mono text-xs text-primary hover:underline">
            {r.source_po.number ?? '—'}
          </Link>
        ) : (
          <span className="text-muted-foreground">—</span>
        )}
      </DocCell>
    ),
  },
  { key: 'pemeriksa', header: 'Pemeriksa', cell: (r) => <span className="whitespace-nowrap">{r.pemeriksa ?? '—'}</span> },
  { key: 'lokasi', header: 'Lokasi', cell: (r) => <span className="whitespace-nowrap">{lokasiText(r) ?? '—'}</span> },
  {
    key: 'seri',
    header: 'No. Seri',
    className: 'max-w-[160px]',
    cell: (r) => <span className="block truncate" title={r.no_seri ?? undefined}>{r.no_seri ?? '—'}</span>,
  },
  {
    key: 'keterangan',
    header: 'Keterangan',
    className: 'max-w-[220px]',
    cell: (r) => <span className="block truncate" title={r.keterangan ?? undefined}>{r.keterangan ?? '—'}</span>,
  },
]

export function RiListPage() {
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const [formOpen, setFormOpen] = useState(false)
  // ?search=<no. dokumen> — opened from a document link elsewhere (DocLink)
  const [params] = useSearchParams()
  const urlSearch = params.get('search') ?? ''
  const [search, setSearch] = useRemembered('ri', 'search', urlSearch)
  const [divisi, setDivisi] = useRemembered('ri', 'divisi', '')
  const [vendor, setVendor] = useRemembered('ri', 'vendor', '')
  const [sumber, setSumber] = useRemembered('ri', 'sumber', '')
  const [dateFrom, setDateFrom] = useRemembered('ri', 'dateFrom', '')
  const [dateTo, setDateTo] = useRemembered('ri', 'dateTo', '')
  const [page, setPage] = useRemembered('ri', 'page', 1)
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
    search: search || undefined,
    divisi: divisi || undefined,
    vendor: vendor || undefined,
    sumber: sumber || undefined,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
  }
  const { data, isLoading } = useRiList({ ...activeFilters, page })
  const { data: vendorOptions } = useRiVendorOptions()
  const resetPage = <T,>(fn: (v: T) => void) => (v: T) => { fn(v); setPage(1) }
  const hasFilters = !!(search || divisi || vendor || sumber || dateFrom || dateTo)
  const clearFilters = () => { setSearch(''); setDivisi(''); setVendor(''); setSumber(''); setDateFrom(''); setDateTo(''); setPage(1) }
  // scroll to the end instead of Sebelumnya/Berikutnya (pages appended while scrolling)
  const { rows: scrollRows, footer: scrollFooter } = useInfiniteRows(data, page, setPage, 'ri')

  const [hiddenCols, toggleCol] = useHiddenColumns('stockwise:ri-hidden-columns')
  const shownColumns = visibleColumns(columns, hiddenCols, ALWAYS_VISIBLE)
  const visibleKeys = shownColumns.map((c) => c.key).join(',')

  return (
    <div className="space-y-4">
      <PageHeader
        title="RI"
        subtitle="Penerimaan barang dari vendor (barang masuk)"
        icon={<Truck className="size-5" />}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ExportBar dataset="ri" filters={activeFilters} filenameBase="ri" viewPermission="report.ri" columns={visibleKeys} />
            {hasPermission('ri.create') && (
              <Button size="sm" onClick={() => setFormOpen(true)}>
                <Plus className="size-4" />
                Tambah RI
              </Button>
            )}
          </div>
        }
      />
      <AccurateDataNote className="-mt-2" />

      <FilterBar className="grid gap-2 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-end" end={<>
        {data && <span className="text-xs text-muted-foreground">{data.meta.total.toLocaleString('id-ID')} RI</span>}
        <ColumnMenu columns={columns} hiddenCols={hiddenCols} onToggle={toggleCol} alwaysVisible={ALWAYS_VISIBLE} />
      </>}>
        <div className="sm:col-span-2 lg:w-80">
          <Input
            placeholder="Cari no RI, no PPB/PO, kode barang, deskripsi, vendor, pemeriksa, keterangan…"
            title="Bisa beberapa kata sekaligus semua kata harus cocok, ke kolom mana saja"
            value={search}
            onChange={(e) => resetPage(setSearch)(e.target.value)}
          />
        </div>
        <div className="lg:w-44">
          <Select value={divisi} onChange={(e) => resetPage(setDivisi)(e.target.value)} aria-label="Jenis RI">
            <option value="">Semua jenis RI</option>
            {JENIS_RI.map((d) => <option key={d} value={d}>{riJenis(d)}</option>)}
          </Select>
        </div>
        <div className="lg:w-48">
          <Select value={sumber} onChange={(e) => resetPage(setSumber)(e.target.value)} aria-label="Sumber RI">
            <option value="">Semua sumber</option>
            {Object.entries(RI_SOURCE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </Select>
        </div>
        <div className="lg:w-48">
          <Select value={vendor} onChange={(e) => resetPage(setVendor)(e.target.value)} aria-label="Vendor">
            <option value="">Semua vendor</option>
            {vendorOptions?.map((v) => <option key={v} value={v}>{v}</option>)}
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
        rows={scrollRows} scrollMemoryKey="ri"
        footer={scrollFooter}
        notes={{ entity: 'ri' }}
        rowKey={(r) => r.id}
        isLoading={isLoading}
        onRowClick={(r) => navigate(`/ri/${r.id}`)}
        emptyText={hasFilters ? 'Tidak ada RI untuk filter ini.' : 'Belum ada data RI jalankan Sync Accurate.'}
      />

      <RiFormModal open={formOpen} onClose={() => setFormOpen(false)} />
    </div>
  )
}
