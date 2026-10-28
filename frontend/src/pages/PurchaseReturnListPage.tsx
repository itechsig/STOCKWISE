import { useNavigate } from 'react-router-dom'
import { Plus, Undo2, X } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { RETURN_STATUS, usePurchaseReturns, type PurchaseReturn, type ReturnStatus } from '@/features/purchaseReturns/api'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, type Column } from '@/components/DataTable'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { useRemembered } from '@/lib/listMemory'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { FilterBar } from '@/components/FilterBar'
import { ColumnMenu, useHiddenColumns, visibleColumns } from '@/components/ColumnVisibility'

const fmtDate = (v: string | null) => (v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '-')

export function ReturnStatusBadge({ status }: { status: ReturnStatus }) {
  const s = RETURN_STATUS[status]
  return <Badge variant={s.variant}>{s.label}</Badge>
}

const fmtQty = (n: number | null | undefined) => Number(n ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 2 })

const columns: Column<PurchaseReturn>[] = [
  {
    key: 'vendor',
    header: 'Vendor',
    cell: (r) => (
      <div className="leading-tight">
        <div className="font-medium">{r.vendor ?? '-'}</div>
        <div className="text-xs text-muted-foreground">{r.reason}</div>
      </div>
    ),
  },
  {
    key: 'retur',
    header: 'Retur',
    cell: (r) => (
      <div className="leading-tight">
        <div className="whitespace-nowrap font-mono text-xs">{r.number}</div>
        <div className="whitespace-nowrap text-xs text-muted-foreground">dari {r.no_ri} · {r.lines_count} barang</div>
      </div>
    ),
  },
  {
    key: 'barang',
    header: 'Barang diretur',
    cell: (r) => (
      <ul className="space-y-0.5 text-xs">
        {(r.items ?? []).slice(0, 3).map((it, i) => (
          <li key={i} className="flex justify-between gap-3"><span className="min-w-0 truncate">{it.deskripsi_barang}</span><span className="shrink-0 tabular-nums">{fmtQty(it.qty)} {it.satuan ?? ''}</span></li>
        ))}
        {(r.items?.length ?? 0) > 3 && <li className="text-muted-foreground">+{r.items!.length - 3} barang lain</li>}
      </ul>
    ),
    className: 'max-w-xs',
  },
  { key: 'qty', header: 'Qty Retur', className: 'text-right', cell: (r) => <span className="font-semibold tabular-nums">{fmtQty(r.total_qty)}</span> },
  { key: 'tgl', header: 'Tanggal', cell: (r) => <span className="whitespace-nowrap text-muted-foreground">{fmtDate(r.tgl_retur)}</span> },
  { key: 'status', header: 'Status', cell: (r) => <ReturnStatusBadge status={r.status} /> },
]

/** Retur Pembelian: open ones first (not sent yet, then waiting for the vendor). */
export function PurchaseReturnListPage() {
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const [search, setSearch] = useRemembered('purchase-returns', 'search', '')
  const [status, setStatus] = useRemembered('purchase-returns', 'status', '')
  const [page, setPage] = useRemembered('purchase-returns', 'page', 1)
  const { data, isLoading } = usePurchaseReturns({ search: search || undefined, status: status || undefined, page })
  const { rows, footer } = useInfiniteRows(data, page, setPage, 'purchase-returns')
  const filtered = !!(search || status)
  const [hiddenCols, toggleCol] = useHiddenColumns('stockwise:purchase-return-hidden-columns')

  return (
    <div className="space-y-4">
      <PageHeader title="Retur Pembelian" icon={<Undo2 className="size-5" />}
        actions={hasPermission('purchase_return.manage') && <Button size="sm" onClick={() => navigate('/purchase-returns/new')}><Plus className="size-4" /> Buat Retur</Button>} />

      <FilterBar end={<div className="max-md:hidden"><ColumnMenu columns={columns} hiddenCols={hiddenCols} onToggle={toggleCol} alwaysVisible={['vendor']} hint="Pilih kolom yang tampil di tabel." /></div>}>
        <Input className="w-full sm:max-w-sm" placeholder="Cari vendor, no retur / RI, barang…" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
        <div className="w-full sm:w-52"><Select aria-label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
          <option value="">Semua status</option>
          {Object.entries(RETURN_STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
        </Select></div>
        {filtered && <Button variant="ghost" size="sm" className="h-10" onClick={() => { setSearch(''); setStatus(''); setPage(1) }}><X className="size-4" /> Reset</Button>}
      </FilterBar>

      <div className="space-y-2 md:hidden">
        {isLoading && rows.length === 0 && <p className="py-6 text-center text-sm text-muted-foreground">Memuat…</p>}
        {!isLoading && rows.length === 0 && <p className="py-6 text-center text-sm text-muted-foreground">{filtered ? 'Tidak ada retur untuk filter ini.' : 'Belum ada retur pembelian.'}</p>}
        {rows.map((r) => (
          <button key={r.id} type="button" onClick={() => navigate(`/purchase-returns/${r.id}`)} className="block w-full rounded-lg border bg-card p-3 text-left active:bg-muted/50">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <div className="font-medium">{r.vendor ?? '-'}</div>
                <div className="text-xs text-muted-foreground">{r.lines_count} barang · qty {fmtQty(r.total_qty)} · {fmtDate(r.tgl_retur)}</div>
                {(r.items ?? []).slice(0, 2).map((it, i) => <div key={i} className="truncate text-xs">{it.deskripsi_barang} · {fmtQty(it.qty)} {it.satuan ?? ''}</div>)}
              </div>
              <ReturnStatusBadge status={r.status} />
            </div>
          </button>
        ))}
        {footer}
      </div>

      <div className="hidden md:block">
        <DataTable columns={visibleColumns(columns, hiddenCols, ['vendor'])} rows={rows} footer={footer} scrollMemoryKey="purchase-returns" rowKey={(r) => r.id} isLoading={isLoading}
          onRowClick={(r) => navigate(`/purchase-returns/${r.id}`)}
          emptyText={filtered ? 'Tidak ada retur untuk filter ini.' : 'Belum ada retur pembelian.'} />
      </div>
    </div>
  )
}
