import { useEffect, useRef } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { FileStack } from 'lucide-react'
import { lokasiText, useNpbgKlasifikasi, useNpbgList } from '@/features/npbg/api'
import { Select } from '@/components/ui/select'
import { ExportBar } from '@/components/export/ExportBar'
import { PageHeader } from '@/components/PageHeader'
import { AccurateDataNote } from '@/components/AccurateDataNote'
import { DataTable, type Column } from '@/components/DataTable'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import type { Npbg } from '@/features/npbg/api'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { useRemembered } from '@/lib/listMemory'
import { KembaliBekasLabel } from '@/components/KembaliBekasToggle'
import { FilterBar } from '@/components/FilterBar'
import { ColumnMenu, useHiddenColumns, visibleColumns } from '@/components/ColumnVisibility'

const VERIFICATION_LABEL: Record<string, string> = {
  DIAJUKAN: 'Diajukan', DIPROSES: 'Diproses', ALTERNATIF_DITAWARKAN: 'Alternatif Ditawarkan',
  MENUNGGU_RESPON: 'Menunggu Respon', PERLU_VERIFIKASI_BOS: 'Perlu Verifikasi BOS',
  DISETUJUI: 'Disetujui', DITOLAK: 'Ditolak', SELESAI: 'Selesai',
}

function fmtDate(v: string | null) {
  return v ? new Date(v).toLocaleDateString('id-ID') : '—'
}

const columns: Column<Npbg>[] = [
  {
    key: 'no_npbg',
    header: 'No NPBG',
    cell: (r) => (
      <span className="inline-flex items-center gap-1.5">
        <Link to={`/npbg/${r.id}`} className="font-mono text-xs text-primary hover:underline">
          {r.no_npbg ?? '—'}
        </Link>
        {r.is_manual && <Badge variant="neutral">Manual</Badge>}
      </span>
    ),
  },
  { key: 'tgl', header: 'Tgl NPBG', cell: (r) => <span className="whitespace-nowrap">{fmtDate(r.tgl_npbg)}</span> },
  {
    key: 'tipe',
    header: 'Tipe NPBG',
    cell: (r) => r.tipe_npbg
      ? <Badge variant={r.tipe_npbg === 'Penjualan' ? 'default' : 'neutral'} className="whitespace-nowrap">{r.tipe_npbg}</Badge>
      : <span className="text-muted-foreground">—</span>,
  },
  { key: 'kode', header: 'Kode Barang', cell: (r) => <span className="whitespace-nowrap font-mono text-xs">{r.kode_barang ?? '—'}</span> },
  {
    key: 'barang',
    header: 'Deskripsi Barang',
    className: 'max-w-[260px]',
    cell: (r) => (
      <span className="block truncate" title={r.deskripsi_barang ?? undefined}>
        {r.deskripsi_barang ?? '—'}
      </span>
    ),
  },
  {
    key: 'qty',
    header: 'Kuantitas',
    cell: (r) => <span className="whitespace-nowrap">{r.kuantitas != null ? `${r.kuantitas} ${r.satuan ?? ''}` : '—'}</span>,
  },
  {
    key: 'kembali_bekas',
    header: 'Kembali Bekas',
    cell: (r) => <span className="whitespace-nowrap"><KembaliBekasLabel npbg={r} /></span>,
  },
  {
    key: 'keterangan',
    header: 'Keterangan',
    className: 'max-w-[220px]',
    cell: (r) => (
      <span className="block truncate" title={r.keterangan ?? undefined}>
        {r.keterangan ?? '—'}
      </span>
    ),
  },
    { key: 'peminta', header: 'Peminta', cell: (r) => <span className="whitespace-nowrap">{r.peminta ?? '—'}</span> },
    { key: 'divisi', header: 'Divisi', cell: (r) => <span className="whitespace-nowrap">{r.divisi ?? '—'}</span> },
    { key: 'lokasi', header: 'Lokasi', cell: (r) => <span className="whitespace-nowrap">{lokasiText(r) ?? '—'}</span> },
  {
    key: 'pelanggan',
    header: 'Pelanggan',
    className: 'max-w-[200px]',
    cell: (r) => (
      <span className="block truncate" title={r.pelanggan ?? undefined}>
        {r.pelanggan ?? '—'}
      </span>
    ),
  },
  { key: 'klasifikasi', header: 'Klasifikasi', cell: (r) => <span className="whitespace-nowrap">{r.klasifikasi ?? '—'}</span> },
  {
    key: 'klarifikasi',
    header: 'Klarifikasi',
    cell: (r) =>
      r.latest_verification_status ? (
        <Badge variant={r.latest_verification_status === 'SELESAI' ? 'success' : 'warning'}>
          {VERIFICATION_LABEL[r.latest_verification_status] ?? r.latest_verification_status}
        </Badge>
      ) : (
        '—'
      ),
  },
]

export function NpbgListPage() {
  // ?search=<no. dokumen> — opened from a document link elsewhere (DocLink)
  const [params] = useSearchParams()
  const urlSearch = params.get('search') ?? ''
  const [search, setSearch] = useRemembered('npbg', 'search', urlSearch)
  // ?date_from= / ?date_to= — e.g. from the stock opname NPBG / PPB columns
  const urlFrom = params.get('date_from') ?? ''
  const urlTo = params.get('date_to') ?? ''
  const [dateFrom, setDateFrom] = useRemembered('npbg', 'dateFrom', urlFrom)
  const [dateTo, setDateTo] = useRemembered('npbg', 'dateTo', urlTo)
  const [klasifikasi, setKlasifikasi] = useRemembered('npbg', 'klasifikasi', '')
  const [tipe, setTipe] = useRemembered('npbg', 'tipe', '')
  const [kembaliBekas, setKembaliBekas] = useRemembered('npbg', 'kembaliBekas', '')
  const klasifikasiOptions = useNpbgKlasifikasi()
  const [page, setPage] = useRemembered('npbg', 'page', 1)
  // first render: the state above already starts from the URL (or is restored after Back);
  // only later URL changes (a document link opened while here) reset the filters
  const lastUrl = useRef([urlSearch, urlFrom, urlTo].join('|'))
  useEffect(() => {
    const now = [urlSearch, urlFrom, urlTo].join('|')
    if (now === lastUrl.current) return
    lastUrl.current = now
    setSearch(urlSearch)
    setDateFrom(urlFrom)
    setDateTo(urlTo)
    setPage(1)
  }, [urlSearch, urlFrom, urlTo])
  const activeFilters = {
    search: search || undefined,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
    tipe_npbg: tipe || undefined,
    klasifikasi: klasifikasi || undefined,
    kembali_bekas: kembaliBekas || undefined,
  }
  const { data, isLoading } = useNpbgList({ ...activeFilters, page })
  // scroll to the end instead of Sebelumnya/Berikutnya (pages appended while scrolling)
  const { rows: scrollRows, footer: scrollFooter } = useInfiniteRows(data, page, setPage, 'npbg')
  const [hiddenCols, toggleCol] = useHiddenColumns('stockwise:npbg-hidden-columns')
  const shownColumns = visibleColumns(columns, hiddenCols, ['no_npbg'])


  return (
    <div className="space-y-4">
      <PageHeader
        title="NPBG"
        subtitle="Nota pengeluaran barang dari gudang (barang keluar)"
        icon={<FileStack className="size-5" />}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ExportBar dataset="npbg" filters={activeFilters} filenameBase="npbg" viewPermission="report.npbg" />
          </div>
        }
      />
      <AccurateDataNote className="-mt-2" />


      <FilterBar end={<ColumnMenu columns={columns} hiddenCols={hiddenCols} onToggle={toggleCol} alwaysVisible={['no_npbg']} hint="Pilih kolom yang tampil di tabel." />}>
        <Input
          placeholder="Cari no NPBG, kode barang, deskripsi, keterangan, peminta, divisi, pelanggan, rak, no seri…"
          className="max-w-sm"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
        />
        <div className="space-y-1">
          <label className="text-xs text-muted-foreground">Dari tanggal</label>
          <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1) }} />
        </div>
        <div className="space-y-1">
          <label className="text-xs text-muted-foreground">Sampai tanggal</label>
          <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1) }} />
        </div>
        <div className="space-y-1">
          <label className="text-xs text-muted-foreground">Tipe NPBG</label>
          <Select value={tipe} onChange={(e) => { setTipe(e.target.value); setPage(1) }} className="w-44">
            <option value="">Semua tipe</option>
            <option value="Penjualan">Penjualan</option>
            <option value="Non Penjualan">Non Penjualan</option>
          </Select>
        </div>
        <div className="space-y-1">
          <label className="text-xs text-muted-foreground">Klasifikasi</label>
          <Select value={klasifikasi} onChange={(e) => { setKlasifikasi(e.target.value); setPage(1) }} className="w-56">
            <option value="">Semua klasifikasi</option>
            {(klasifikasiOptions.data ?? []).map((j) => <option key={j} value={j}>{j}</option>)}
          </Select>
        </div>
        <div className="space-y-1">
          <label className="text-xs text-muted-foreground">Kembali bekas</label>
          <Select value={kembaliBekas} onChange={(e) => { setKembaliBekas(e.target.value); setPage(1) }} className="w-44">
            <option value="">Semua</option>
            <option value="1">Dicentang</option>
            <option value="0">Tidak dicentang</option>
          </Select>
        </div>
      </FilterBar>

      <DataTable columns={shownColumns} rows={scrollRows} scrollMemoryKey="npbg"
        footer={scrollFooter} notes={{ entity: 'npbg' }} rowKey={(r) => r.id} isLoading={isLoading}
 emptyText="Belum ada data NPBG jalankan Sync Accurate." />

    </div>
  )
}
