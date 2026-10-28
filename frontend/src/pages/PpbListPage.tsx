import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Clock, FileStack, Plus, X } from 'lucide-react'
import { PPB_LINE_STATUS, ppbDivisi, usePpbDivisiOptions, usePpbList, usePpbVendorOptions, type Ppb } from '@/features/ppb/api'
import { useAuth } from '@/auth/AuthContext'
import { PpbStatusBadges } from '@/components/PpbStatusBadges'
import { PpbApprovalBadge } from '@/components/PpbApproval'
import { PpbFormModal } from '@/components/PpbFormModal'
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
import { QtyProgress } from '@/components/QtyProgress'
import { useOpenDraftCount } from '@/features/ppbDrafts/api'

function fmtDate(v: string | null | undefined) {
  return v ? new Date(v).toLocaleDateString('id-ID') : '—'
}

const ALWAYS_VISIBLE = ['no_ppb']

/** Diterima vs diminta sebagai bar kecil — lebih cepat dibaca sekilas daripada "12/20". */

/** Nomor + tanggal dipasangkan satu sel, dan Keterangan + Catatan Baris digabung satu kolom — tabel tetap ringkas tanpa kehilangan data. */
const columns: Column<Ppb>[] = [
  {
    key: 'no_ppb',
    header: 'No PPB',
    cell: (r) => (
      <DocCell date={fmtDate(r.tgl_ppb)}>
        <span className="inline-flex items-center gap-1.5">
          <Link to={`/ppb/${r.id}`} onClick={(e) => e.stopPropagation()} className="font-mono text-xs font-medium text-primary hover:underline">
            {r.no_ppb ?? '—'}
          </Link>
          {r.is_manual && <Badge variant="neutral">Manual</Badge>}
        </span>
      </DocCell>
    ),
  },
  { key: 'status', header: 'Status', cell: (r) => <PpbStatusBadges ppb={r} /> },
  { key: 'approval', header: 'Approval', cell: (r) => <span className="whitespace-nowrap"><PpbApprovalBadge approval={r.approval} /></span> },
  { key: 'divisi', header: 'Divisi', cell: (r) => <span className="whitespace-nowrap">{ppbDivisi(r.divisi) ?? '—'}</span> },
  { key: 'kode', header: 'Kode Barang', cell: (r) => <span className="whitespace-nowrap font-mono text-xs">{r.kode_barang ?? '—'}</span> },
  {
    key: 'barang',
    header: 'Deskripsi Barang',
    className: 'max-w-[260px]',
    cell: (r) => <span className="block truncate" title={r.deskripsi_barang ?? undefined}>{r.deskripsi_barang ?? '—'}</span>,
  },
  {
    key: 'qty',
    header: 'Kuantitas',
    className: 'text-right',
    cell: (r) => <span className="whitespace-nowrap tabular-nums">{r.kuantitas != null ? `${r.kuantitas} ${r.satuan ?? ''}` : '—'}</span>,
  },
  // asked / received by RI so far: a partial delivery reads "10/5"
  { key: 'diterima', header: 'Diminta/Diterima (RI)', cell: (r) => <QtyProgress total={r.kuantitas} done={r.qty_diterima ?? 0} unit={r.satuan} /> },
  { key: 'peminta', header: 'Peminta', cell: (r) => <span className="whitespace-nowrap">{r.peminta ?? '—'}</span> },
  // Rantai dokumen PPB -> PO -> RI, dikelompokkan sebagai dua sel (bukan empat
  // kolom) agar alur pembelian tetap terbaca tanpa membuat tabel melebar.
  {
    key: 'vendor',
    header: 'Vendor',
    className: 'max-w-[220px]',
    cell: (r) => r.vendor
      ? <span className="block truncate" title={r.vendor}>{r.vendor}</span>
      : <span className="whitespace-nowrap text-xs text-muted-foreground">{r.po_no ? '' : 'Belum ada PO'}</span>,
  },
  { key: 'po', header: 'PO', cell: (r) => <DocCell date={fmtDate(r.po_date)}><DocText text={r.po_no} className="font-mono text-xs" /></DocCell> },
  { key: 'ri', header: 'RI', cell: (r) => <DocCell date={fmtDate(r.ri_date)}><DocText text={r.ri_no} className="font-mono text-xs" /></DocCell> },
  {
    key: 'catatan',
    header: 'Catatan',
    className: 'max-w-[220px]',
    cell: (r) => (
      <div className="leading-tight">
        <div className="truncate" title={r.keterangan ?? undefined}>{r.keterangan ?? '—'}</div>
        {r.catatan_baris && r.catatan_baris !== r.keterangan && (
          <div className="truncate text-xs text-muted-foreground" title={r.catatan_baris}>{r.catatan_baris}</div>
        )}
      </div>
    ),
  },
]

export function PpbListPage() {
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const openDrafts = useOpenDraftCount(hasPermission('ppb.create'))
  const [formOpen, setFormOpen] = useState(false)
  // ?search=<no. dokumen> — opened from a document link elsewhere (DocLink)
  const [params] = useSearchParams()
  const urlSearch = params.get('search') ?? ''
  const [search, setSearch] = useRemembered('ppb', 'search', urlSearch)
  const [lineStatus, setLineStatus] = useRemembered('ppb', 'lineStatus', '')
  const [divisi, setDivisi] = useRemembered('ppb', 'divisi', '')
  const [vendorId, setVendorId] = useRemembered('ppb', 'vendorId', '')
  // ?approval=MENUNGGU — opened from an approval notification
  const urlApproval = params.get('approval') ?? ''
  const [approval, setApproval] = useRemembered('ppb', 'approval', urlApproval)
  // ?date_from= / ?date_to= — e.g. from the stock opname NPBG / PPB columns
  const urlFrom = params.get('date_from') ?? ''
  const urlTo = params.get('date_to') ?? ''
  const [dateFrom, setDateFrom] = useRemembered('ppb', 'dateFrom', urlFrom)
  const [dateTo, setDateTo] = useRemembered('ppb', 'dateTo', urlTo)
  const [page, setPage] = useRemembered('ppb', 'page', 1)
  // first render: the state above already starts from the URL (or is restored after Back);
  // only later URL changes (a document link opened while here) reset the filters
  const lastUrl = useRef([urlSearch, urlFrom, urlTo, urlApproval].join('|'))
  useEffect(() => {
    const now = [urlSearch, urlFrom, urlTo, urlApproval].join('|')
    if (now === lastUrl.current) return
    lastUrl.current = now
    setSearch(urlSearch)
    setDateFrom(urlFrom)
    setDateTo(urlTo)
    setApproval(urlApproval)
    setPage(1)
  }, [urlSearch, urlFrom, urlTo, urlApproval])
  const activeFilters = {
    search: search || undefined,
    line_status: lineStatus || undefined,
    divisi: divisi || undefined,
    vendor_id: vendorId || undefined,
    approval: approval || undefined,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
  }
  const { data, isLoading } = usePpbList({ ...activeFilters, page })
  // approvers: PPB lines still waiting for them — sent along with the list (no extra request)
  const waitingCount = ['ppb.approve', 'ppb.approve_manager', 'ppb.approve_director'].some((p) => hasPermission(p)) ? (data?.meta as { approval_waiting?: number | null } | undefined)?.approval_waiting ?? 0 : 0
  const { data: divisiOptions } = usePpbDivisiOptions()
  const { data: vendorOptions } = usePpbVendorOptions()
  const resetPage = <T,>(fn: (v: T) => void) => (v: T) => { fn(v); setPage(1) }
  const hasFilters = !!(search || lineStatus || divisi || vendorId || approval || dateFrom || dateTo)
  const clearFilters = () => { setSearch(''); setLineStatus(''); setDivisi(''); setVendorId(''); setApproval(''); setDateFrom(''); setDateTo(''); setPage(1) }
  // scroll to the end instead of Sebelumnya/Berikutnya (pages appended while scrolling)
  const { rows: scrollRows, footer: scrollFooter } = useInfiniteRows(data, page, setPage, 'ppb')

  const [hiddenCols, toggleCol] = useHiddenColumns('stockwise:ppb-hidden-columns')
  const shownColumns = visibleColumns(columns, hiddenCols, ALWAYS_VISIBLE)
  const visibleKeys = shownColumns.map((c) => c.key).join(',')

  return (
    <div className="space-y-4">
      <PageHeader
        title="PPB"
        subtitle="Permintaan pembelian barang (PPB)"
        icon={<FileStack className="size-5" />}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ExportBar dataset="ppb" filters={activeFilters} filenameBase="ppb" viewPermission="report.ppb" columns={visibleKeys} />
            {hasPermission('ppb.create') && (
              <Button size="sm" variant="outline" asChild>
                <Link to="/ppb/drafts">Draft PPB{openDrafts.data ? <span className="rounded-full bg-amber-400 px-1.5 text-[11px] font-semibold text-amber-950">{openDrafts.data}</span> : null}</Link>
              </Button>
            )}
            {hasPermission('ppb.create') && (
              <Button size="sm" onClick={() => setFormOpen(true)}>
                <Plus className="size-4" />
                Tambah PPB
              </Button>
            )}
          </div>
        }
      />
      <AccurateDataNote className="-mt-2" />

      {waitingCount > 0 && approval !== 'MENUNGGU' && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <span className="flex items-center gap-2">
            <Clock className="size-4 shrink-0" />
            <span><b>{waitingCount.toLocaleString('id-ID')} barang PPB</b> menunggu approval Anda sebelum bisa jadi PO.</span>
          </span>
          <Button size="sm" onClick={() => { setApproval('MENUNGGU'); setPage(1) }}>Lihat & approve</Button>
        </div>
      )}

      <FilterBar className="grid gap-2 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-end" end={<>
        {data && <span className="text-xs text-muted-foreground">{data.meta.total.toLocaleString('id-ID')} PPB</span>}
        <ColumnMenu columns={columns} hiddenCols={hiddenCols} onToggle={toggleCol} alwaysVisible={ALWAYS_VISIBLE} />
      </>}>
        <div className="sm:col-span-2 lg:w-72">
          <Input
            placeholder="Cari no PPB, barang, peminta, vendor, catatan, no PO/RI…"
            title="Bisa beberapa kata sekaligus semua kata harus cocok, ke kolom mana saja"
            value={search}
            onChange={(e) => resetPage(setSearch)(e.target.value)}
          />
        </div>
        <div className="lg:w-48">
          <Select value={lineStatus} onChange={(e) => resetPage(setLineStatus)(e.target.value)} aria-label="Status">
            <option value="">Semua status</option>
            {Object.entries(PPB_LINE_STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
            <option value="AMEND">Amend (status apa pun)</option>
          </Select>
        </div>
        <div className="lg:w-44">
          <Select value={divisi} onChange={(e) => resetPage(setDivisi)(e.target.value)} aria-label="Divisi">
            <option value="">Semua divisi</option>
            {divisiOptions?.map((d) => <option key={d} value={d}>{d}</option>)}
          </Select>
        </div>
        <div className="lg:w-60">
          <Select value={vendorId} onChange={(e) => resetPage(setVendorId)(e.target.value)} aria-label="Vendor" title="Vendor dari PO yang dibuat untuk PPB ini pilih &quot;Belum ada PO&quot; untuk PPB yang belum dibuatkan PO">
            <option value="">Semua vendor</option>
            <option value="none">Belum ada PO</option>
            {vendorOptions?.map((v) => <option key={v.id} value={String(v.id)}>{v.name}</option>)}
          </Select>
        </div>
        <div className="lg:w-52">
          <Select value={approval} onChange={(e) => resetPage(setApproval)(e.target.value)} aria-label="Approval">
            <option value="">Semua approval</option>
            <option value="MENUNGGU">Menunggu approval</option>
            <option value="MENUNGGU_DIREKTUR">Menunggu Direktur</option>
            <option value="DISETUJUI">Disetujui</option>
            <option value="DITOLAK">Ditolak</option>
            <option value="PO_TANPA_APPROVAL">PO tanpa approval</option>
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

      <p className="text-xs text-muted-foreground">
        Status per baris barang (dari Accurate): {Object.values(PPB_LINE_STATUS).map((v) => `${v.label} = ${v.hint.toLowerCase()}`).join(' · ')}.
        {' '}<b>Amend</b> tampil di sampingnya bila pembelian berubah / tidak jadi dibeli (mis. Amend + Requested).
      </p>

      <DataTable
        columns={shownColumns}
        rows={scrollRows} scrollMemoryKey="ppb"
        footer={scrollFooter}
        notes={{ entity: 'ppb' }}
        rowKey={(r) => r.id}
        isLoading={isLoading}
        onRowClick={(r) => navigate(`/ppb/${r.id}`)}
        emptyText={hasFilters ? 'Tidak ada PPB untuk filter ini.' : 'Belum ada data PPB jalankan Sync Accurate.'}
      />

      <PpbFormModal open={formOpen} onClose={() => setFormOpen(false)} />
    </div>
  )
}
